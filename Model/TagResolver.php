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
     */
    private const GLOBAL_TAG_MAP = [
        'FPC'                       => 'te-fpc',
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

        // Check if this tag should be ignored
        if (in_array($tag, self::IGNORE_TAGS, true)) {
            return null;
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

        // Store-specific tags
        if (strpos($tag, 'store_') === 0) {
            return 'store-' . substr($tag, 6);
        }

        // Store group tags
        if (strpos($tag, 'store_group_') === 0) {
            return 'store-group-' . substr($tag, 12);
        }

        // For any unrecognized tag, pass it through with a "m2-" prefix
        // so it's still useful for targeted invalidation
        return 'm2-' . preg_replace('/[^a-z0-9_-]/', '-', $tag);
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

        return implode(' ', $keys);
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
