<?php
/**
 * Tag resolver: translates Magento cache tags to Transparent Edge Surrogate-Keys
 *
 * Magento uses cache tags like cat_p_123, cat_c_45, cms_p_6, cms_b_7
 * We translate these to compact, human-readable Surrogate-Key values.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

class TagResolver
{
    /**
     * Maximum size of the Surrogate-Keys header value, in bytes.
     *
     * Varnish's default http_resp_hdr_len is 8192 bytes for the whole header
     * line; 6144 leaves room for the header name and for everything else the
     * response carries. Past this the key list degrades — see
     * buildSurrogateKeyHeader().
     */
    public const MAX_HEADER_BYTES = 6144;

    /**
     * Magento tag prefix → Surrogate-Key prefix mapping
     *
     * Magento tag format: {prefix}_{id}
     * TE Surrogate-Key:   {mapped_prefix}-{id}
     */
    private const TAG_MAP = [
        'cat_p'        => 'product',       // Catalog Product
        'cat_c'        => 'category',      // Catalog Category
        'cms_p'        => 'page',          // CMS Page
        'cms_b'        => 'block',         // CMS Block
        'catalog_rule' => 'catalog-rule',  // Catalog Price Rule
        'cart_rule'    => 'cart-rule',      // Cart Price Rule
    ];

    /**
     * Tags that map to a global TE key (no ID suffix)
     *
     * Keys are lowercase on purpose: resolveTag() lowercases the incoming tag
     * before looking it up. Magento's own full-page-cache tag is spelled FPC
     * in uppercase, so an uppercase key here would never be found and the tag
     * would fall through to the generic m2- prefix instead of te-fpc.
     */
    private const GLOBAL_TAG_MAP = [
        'fpc'                       => 'te-fpc',
        'cat_p'                     => 'te-products',
        'cat_c'                     => 'te-categories',
        'cms_p'                     => 'te-pages',
        'cms_b'                     => 'te-blocks',
        'config_scopes'             => 'te-config',
        'layout'                    => 'te-layout',
        'translate'                 => 'te-translate',
        'store_group'               => 'te-store',
        'catalog_product_attribute' => 'te-attributes',
    ];

    /**
     * Tags to ignore (internal Magento only, no CDN relevance)
     */
    private const IGNORE_TAGS = [
        'interception',
        'compiled_config',
        'db_ddl',
        'eav',
        'global_plugin_list',
        'reflection',
        'extension_attributes',
        'customer',
        'quote',
        'checkout',
        'sales',
        'wishlist',
        'review',
        'newsletter',
    ];

    /**
     * Tag prefixes to ignore (third-party modules, payment, sessions, etc.)
     * Any tag starting with one of these prefixes is dropped before reaching the CDN.
     * This prevents irrelevant internal tags (payment tokens, customer sessions, etc.)
     * from being sent as Surrogate-Keys.
     */
    private const IGNORE_PREFIXES = [
        'paycomet_',
        'payment_',
        'token_',
        'quote_',
        'sales_',
        'order_',
        'invoice_',
        'shipment_',
        'creditmemo_',
        'customer_',
        'wishlist_',
        'checkout_',
        'newsletter_',
        'review_',
        'search_',
        'report_',
        'admin_',
        'session_',
        'indexer_',
        'cron_',
        'mview_',
        'sequence_',
        'inventory_reservation',
        'targetrule_',
        'reward_',
        'giftcard_',
    ];

    /**
     * Resolve an array of Magento cache tags to TE Surrogate-Key tags
     *
     * @param  array $magentoTags Magento cache tag strings
     * @return array Surrogate-Key tag strings for TE
     */
    public function resolve(array $magentoTags): array
    {
        $surrogateKeys = [];

        foreach ($magentoTags as $tag) {
            $resolved = $this->resolveTag((string) $tag);
            if ($resolved !== null) {
                $surrogateKeys[] = $resolved;
            }
        }

        // Deduplicate and sort for consistent header values
        $surrogateKeys = array_unique($surrogateKeys);
        sort($surrogateKeys);

        return $surrogateKeys;
    }

    /**
     * Resolve a single Magento cache tag to a TE Surrogate-Key
     *
     * @param  string $tag Magento cache tag
     * @return string|null TE Surrogate-Key or null if tag should be ignored
     */
    public function resolveTag(string $tag): ?string
    {
        $tag = strtolower(trim($tag));

        if (empty($tag)) {
            return null;
        }

        // Check if this tag should be ignored (exact match)
        if (in_array($tag, self::IGNORE_TAGS, true)) {
            return null;
        }

        // Check if this tag matches an ignored prefix (third-party modules, payments, etc.)
        foreach (self::IGNORE_PREFIXES as $prefix) {
            if (strpos($tag, $prefix) === 0) {
                return null;
            }
        }

        // Check global tag mapping (exact match, no ID)
        if (isset(self::GLOBAL_TAG_MAP[$tag])) {
            return self::GLOBAL_TAG_MAP[$tag];
        }

        // Check entity-level tags (prefix_ID format)
        foreach (self::TAG_MAP as $prefix => $surrogatePrefix) {
            if (strpos($tag, $prefix . '_') === 0) {
                $id = substr($tag, strlen($prefix) + 1);
                if ($id !== '' && $id !== false) {
                    return $surrogatePrefix . '-' . $id;
                }
            }
        }

        // Store group tags — must be tested BEFORE the plain store_ prefix,
        // which would otherwise swallow store_group_5 and turn it into
        // store-group_5 (and leave this branch unreachable).
        if (strpos($tag, 'store_group_') === 0) {
            return 'store-group-' . substr($tag, 12);
        }

        // Store-specific tags
        if (strpos($tag, 'store_') === 0) {
            return 'store-' . substr($tag, 6);
        }

        // For unrecognized tags: only pass through known Magento FPC-related tags.
        // Drop everything else to prevent third-party module noise from flooding
        // the CDN with irrelevant Surrogate-Keys (e.g. 110K payment tokens).
        $fpcPrefixes = ['cms_', 'cat_', 'catalog_', 'fpc', 'block_'];
        foreach ($fpcPrefixes as $fpcPrefix) {
            if (strpos($tag, $fpcPrefix) === 0) {
                return 'm2-' . preg_replace('/[^a-z0-9_-]/', '-', $tag);
            }
        }

        // Unknown tag from third-party module — ignore silently
        return null;
    }

    /**
     * Build the Surrogate-Key header value from Magento tags
     *
     * @param  array $magentoTags Magento cache tags
     * @return string Space-separated Surrogate-Key header value
     */
    public function buildSurrogateKeyHeader(array $magentoTags): string
    {
        $keys = $this->resolve($magentoTags);

        // Always add the global key for full-site purge
        $keys[] = 'te-all';

        $keys = array_unique($keys);
        sort($keys);

        $header = implode(' ', $keys);

        if (strlen($header) <= self::MAX_HEADER_BYTES) {
            return $header;
        }

        // The header would not fit. A page showing every product of a large
        // category (?product_list_limit=all) produces one key per product and
        // crosses Varnish's default 8 KB header limit at roughly 600 of them.
        //
        // We degrade to the global keys instead of truncating the list:
        // a truncated list would be a lie — the page would claim keys for the
        // products that happened to fit and silently drop the rest, so
        // invalidating one of the dropped products would leave this page
        // stale forever. The global keys (te-products, te-categories, …) are
        // coarser but still correct, and te-truncated makes the degradation
        // visible in a curl.
        $globals = array_values(array_filter(
            $keys,
            static fn(string $key): bool => strpos($key, 'te-') === 0
        ));
        $globals[] = 'te-truncated';
        $globals = array_unique($globals);
        sort($globals);

        return implode(' ', $globals);
    }

    /**
     * Get the Surrogate-Key for a specific product
     *
     * @param  int $productId
     * @return string
     */
    public function getProductKey(int $productId): string
    {
        return 'product-' . $productId;
    }

    /**
     * Get the Surrogate-Key for a specific category
     *
     * @param  int $categoryId
     * @return string
     */
    public function getCategoryKey(int $categoryId): string
    {
        return 'category-' . $categoryId;
    }

    /**
     * Get the Surrogate-Key for a specific CMS page
     *
     * @param  int $pageId
     * @return string
     */
    public function getPageKey(int $pageId): string
    {
        return 'page-' . $pageId;
    }

    /**
     * Get the Surrogate-Key for a specific CMS block
     *
     * @param  int $blockId
     * @return string
     */
    public function getBlockKey(int $blockId): string
    {
        return 'block-' . $blockId;
    }
}
