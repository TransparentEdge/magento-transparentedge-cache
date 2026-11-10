<?php
/**
 * Transparent Edge CDN integration for Magento 2
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\ScopeInterface;

/**
 * Per-page-type cache profile.
 *
 * A single TTL for every HTML page is a poor fit for a shop. The homepage of
 * an e-commerce changes with every product that goes in or out of stock; a CMS
 * page with the legal notice does not change in a year. Giving them the same
 * 48h edge TTL means either the homepage is stale or the legal notice is
 * fetched far more often than it needs to be.
 *
 * There is also a correctness reason, which is the one that made this
 * necessary rather than merely nice. When a block's HTML comes out of
 * Magento's block_html cache, its collection is never loaded and
 * getIdentities() returns fewer identities, so the page is tagged with the
 * global cat_p instead of one tag per product. The edge almost never caches
 * the first render after a flush, so the stored homepage usually carries
 * te-products but not product-1556: changing that product invalidates a key
 * the object does not have, and the homepage stays stale until its TTL runs
 * out. A short fresh window plus a long stale-while-revalidate makes the page
 * refresh itself on time regardless of whether the tag reaches it — the stale
 * copy is served instantly while a single request revalidates behind it.
 *
 * Page types come from the request's full action name, which Magento has
 * already resolved by the time the response is sent. That is exact, unlike
 * guessing from the URL or from the <body> class.
 */
class CacheProfile
{
    public const TYPE_HOME     = 'home';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_PRODUCT  = 'product';
    public const TYPE_CMS      = 'cms';
    public const TYPE_DEFAULT  = 'default';

    /**
     * Magento full action name => our page type.
     *
     * cms_index_index is the homepage; cms_page_view is any other CMS page.
     */
    private const ACTION_MAP = [
        'cms_index_index'      => self::TYPE_HOME,
        'catalog_category_view' => self::TYPE_CATEGORY,
        'catalog_product_view'  => self::TYPE_PRODUCT,
        'cms_page_view'         => self::TYPE_CMS,
    ];

    private const PATH_ENABLED = 'transparentedge/cache_profiles/enabled';
    private const PATH_PREFIX  = 'transparentedge/cache_profiles/';

    /**
     * Defaults, in seconds: [edge TTL, stale-while-revalidate].
     *
     * Page types do not age at the same speed, so one TTL for all of them is
     * wrong in both directions at once: too long for the homepage, needlessly
     * short for a legal notice. A value of 0 inherits the module-wide HTML
     * setting.
     *
     * Every type gets a stale window that is much longer than its fresh
     * window. That is what makes short TTLs cheap: once the fresh window
     * passes, the edge still answers instantly from the stale copy while a
     * single request revalidates behind it. Nobody waits.
     *
     *  - Homepage: changes with every product and every stock movement, and
     *    is the page that loses its product keys to Magento's block_html
     *    cache (see the class docblock). Shortest window of the four.
     *  - Category: changes with the catalogue, and is tagged reliably.
     *  - Product: tagged reliably, so it can lean on invalidation.
     *  - CMS: rarely changes at all.
     */
    private const DEFAULTS = [
        self::TYPE_HOME     => ['ttl' => 300,    'swr' => 86400],
        self::TYPE_CATEGORY => ['ttl' => 3600,   'swr' => 86400],
        self::TYPE_PRODUCT  => ['ttl' => 86400,  'swr' => 86400],
        self::TYPE_CMS      => ['ttl' => 172800, 'swr' => 172800],
    ];

    private ScopeConfigInterface $scopeConfig;
    private HttpRequest $request;
    private Config $config;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        HttpRequest $request,
        Config $config
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->request     = $request;
        $this->config      = $config;
    }

    /**
     * The page type of the request being answered.
     */
    public function getPageType(): string
    {
        $action = (string) $this->request->getFullActionName();

        return self::ACTION_MAP[$action] ?? self::TYPE_DEFAULT;
    }

    /**
     * Cache-Control value for the current request's HTML.
     *
     * Falls back to the module-wide HTML profile when profiles are off, when
     * the page type is not one we treat specially, or when the configured
     * values for that type are empty.
     */
    public function buildHtmlCacheControl(): string
    {
        $type = $this->getPageType();

        if (!$this->isEnabled() || $type === self::TYPE_DEFAULT) {
            return $this->config->buildHtmlCacheControl();
        }

        $ttl = $this->getTtl($type);
        $swr = $this->getSwr($type);

        if ($ttl <= 0) {
            return $this->config->buildHtmlCacheControl();
        }

        return implode(', ', [
            'public',
            sprintf('s-maxage=%d', $ttl),
            sprintf('max-age=%d', min($this->config->getHtmlBrowserTtl(), $ttl)),
            sprintf('stale-while-revalidate=%d', $swr > 0 ? $swr : $this->config->getStaleWhileRevalidate()),
            sprintf('stale-if-error=%d', $this->config->getStaleIfError()),
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Edge TTL for a page type, in seconds. 0 means "use the global value".
     */
    public function getTtl(string $type): int
    {
        if (!isset(self::DEFAULTS[$type])) {
            return 0;
        }

        $value = $this->scopeConfig->getValue(self::PATH_PREFIX . $type . '_ttl', ScopeInterface::SCOPE_STORE);

        return $value === null || $value === '' ? self::DEFAULTS[$type]['ttl'] : (int) $value;
    }

    /**
     * stale-while-revalidate for a page type, in seconds.
     */
    public function getSwr(string $type): int
    {
        if (!isset(self::DEFAULTS[$type])) {
            return 0;
        }

        $value = $this->scopeConfig->getValue(self::PATH_PREFIX . $type . '_swr', ScopeInterface::SCOPE_STORE);

        return $value === null || $value === '' ? self::DEFAULTS[$type]['swr'] : (int) $value;
    }

    /**
     * Every profile, for the admin panel and the status command.
     *
     * @return array<string, array{type:string, ttl:int, swr:int}>
     */
    public function getAll(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $type) {
            $out[$type] = ['type' => $type, 'ttl' => $this->getTtl($type), 'swr' => $this->getSwr($type)];
        }

        return $out;
    }
}
