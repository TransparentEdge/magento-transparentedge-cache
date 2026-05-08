<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\SpeculationRules;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Collects URL patterns that must be excluded from Speculation Rules.
 *
 * These exclusions prevent the browser from speculatively fetching pages
 * that modify state (cart, checkout, payment), require authentication
 * (customer account), or are not public content (admin, REST, GraphQL).
 *
 * The exclusion list is intentionally conservative and non-configurable
 * to prevent accidental prefetching of destructive endpoints.
 */
class ExclusionCollector
{
    /**
     * Magento core paths that must NEVER be prefetched.
     * These modify state, require authentication, or are internal endpoints.
     */
    private const CORE_EXCLUSIONS = [
        // Checkout & payment (can create orders, modify cart)
        '/checkout/*',
        '/multishipping/*',
        '/paypal/*',
        '/braintree/*',
        '/adyen/*',
        '/stripe/*',

        // Customer area (session-dependent, private data)
        '/customer/*',
        '/wishlist/*',
        '/persistent/*',
        '/newsletter/*',
        '/vault/*',
        '/instant-purchase/*',
        '/review/product/post/*',
        '/sendfriend/*',
        '/contact/index/post/',
        '/downloadable/*',
        '/sales/order/*',
        '/sales/guest/*',

        // API & internal endpoints
        '/rest/*',
        '/graphql',
        '/graphql/*',
        '/customer/section/load/*',
        '/directory/currency/switch/*',
        '/stores/store/switch/*',

        // Search (high cardinality, poor cache hit ratio)
        '/catalogsearch/result/*',
        '/search/*',

        // Admin (dynamic, authenticated)
        '/admin/*',

        // Session & tracking
        '/*?SID=*',

        // AJAX endpoints (Magento convention)
        '/*?ajax=*',
    ];

    /**
     * Common payment gateway paths (third-party extensions).
     */
    private const PAYMENT_EXCLUSIONS = [
        '/sagepay/*',
        '/worldpay/*',
        '/paycomet/*',
        '/redsys/*',
        '/klarna/*',
        '/amazon_pay/*',
        '/mollie/*',
        '/square/*',
    ];

    /**
     * Link attributes that should prevent speculation.
     * Used as CSS selector exclusions in the rules.
     */
    private const SELECTOR_EXCLUSIONS = [
        '[rel~=nofollow]',
        '[download]',
        '[target=_blank]',
    ];

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Get all href_matches exclusion patterns
     *
     * @return array URL patterns to exclude
     */
    public function getPathExclusions(): array
    {
        $exclusions = array_merge(
            self::CORE_EXCLUSIONS,
            self::PAYMENT_EXCLUSIONS,
            $this->getAdminPathExclusion(),
            $this->getCustomExclusions()
        );

        return array_unique(array_filter($exclusions));
    }

    /**
     * Get CSS selector-based exclusions
     *
     * @return array Selector patterns
     */
    public function getSelectorExclusions(): array
    {
        return self::SELECTOR_EXCLUSIONS;
    }

    /**
     * Detect the actual admin path (can be customized in env.php)
     *
     * @return array
     */
    private function getAdminPathExclusion(): array
    {
        $adminPath = $this->scopeConfig->getValue('admin/url/custom_path');
        if ($adminPath && $adminPath !== 'admin') {
            return ['/' . ltrim($adminPath, '/') . '/*'];
        }
        return [];
    }

    /**
     * Get exclusions from the plugin's "Exclude URLs" setting
     * (already used for CDN cache exclusions, reuse for speculation)
     *
     * @return array
     */
    private function getCustomExclusions(): array
    {
        $urls = (string) $this->scopeConfig->getValue(
            'transparentedge/advanced/exclude_urls',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($urls)) {
            return [];
        }

        $paths = [];
        foreach (array_filter(array_map('trim', explode("\n", $urls))) as $url) {
            // Ensure paths start with /
            if (strpos($url, '/') !== 0 && strpos($url, 'http') !== 0) {
                $url = '/' . $url;
            }
            $paths[] = $url;
        }

        return $paths;
    }
}
