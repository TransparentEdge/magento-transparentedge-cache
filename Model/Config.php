<?php
/**
 * Centralized configuration access for Transparent Edge CDN
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    /**
     * Config path prefixes
     */
    private const PREFIX = 'transparentedge';

    /**
     * General settings
     */
    public const ENABLED               = 'transparentedge/general/enabled';
    public const COMPANY_ID            = 'transparentedge/general/company_id';
    public const CLIENT_ID             = 'transparentedge/general/client_id';
    public const CLIENT_SECRET         = 'transparentedge/general/client_secret';

    /**
     * Cache settings
     */
    public const HTML_TTL              = 'transparentedge/cache/html_ttl';
    public const HTML_BROWSER_TTL      = 'transparentedge/cache/html_browser_ttl';
    public const STATIC_TTL            = 'transparentedge/cache/static_ttl';
    public const STATIC_BROWSER_TTL    = 'transparentedge/cache/static_browser_ttl';
    public const STALE_WHILE_REVALIDATE = 'transparentedge/cache/stale_while_revalidate';
    public const STALE_IF_ERROR        = 'transparentedge/cache/stale_if_error';
    public const SOFT_PURGE            = 'transparentedge/cache/soft_purge';
    public const REFETCH               = 'transparentedge/cache/refetch';

    /**
     * Warm-up settings
     */
    public const WARMUP_ENABLED        = 'transparentedge/warmup/enabled';
    public const WARMUP_HOMEPAGE       = 'transparentedge/warmup/homepage';
    public const WARMUP_CATEGORIES     = 'transparentedge/warmup/categories';
    public const WARMUP_SITEMAP        = 'transparentedge/warmup/sitemap';
    public const WARMUP_SITEMAP_URL    = 'transparentedge/warmup/sitemap_url';
    public const WARMUP_RATE_LIMIT     = 'transparentedge/warmup/rate_limit';
    public const WARMUP_CUSTOM_URLS    = 'transparentedge/warmup/custom_urls';

    /**
     * i3 settings
     */
    public const I3_ENABLED            = 'transparentedge/i3/enabled';
    public const I3_AUTO_WEBP          = 'transparentedge/i3/auto_webp';
    public const I3_QUALITY            = 'transparentedge/i3/quality';
    public const I3_MAX_WIDTH          = 'transparentedge/i3/max_width';

    /**
     * Advanced settings
     */
    public const ADMIN_BYPASS          = 'transparentedge/advanced/admin_bypass';
    public const DEBUG_MODE            = 'transparentedge/advanced/debug';
    public const EXCLUDE_URLS          = 'transparentedge/advanced/exclude_urls';
    public const EXCLUDE_COOKIES       = 'transparentedge/advanced/exclude_cookies';

    /**
     * WPO settings
     */
    public const WPO_ENABLED           = 'transparentedge/wpo/enabled';
    public const WPO_PRELOAD           = 'transparentedge/wpo/preload';
    public const WPO_LAZYLOAD          = 'transparentedge/wpo/lazyload';
    public const WPO_DNS_PREFETCH      = 'transparentedge/wpo/dns_prefetch';

    /**
     * Default TTL values (seconds)
     */
    public const DEFAULT_HTML_TTL           = 172800;    // 48h
    public const DEFAULT_HTML_BROWSER_TTL   = 3600;      // 1h
    public const DEFAULT_STATIC_TTL         = 2592000;   // 30d
    public const DEFAULT_STATIC_BROWSER_TTL = 86400;     // 1d
    public const DEFAULT_SWR                = 86400;     // 1d
    public const DEFAULT_SIE                = 86400;     // 1d
    public const DEFAULT_WARMUP_RATE        = 3;         // req/s

    /**
     * API constants
     */
    public const API_BASE_URL = 'https://api.transparentcdn.com';
    public const API_VERSION  = 'v1';

    /**
     * Cookie name for admin bypass
     */
    public const ADMIN_COOKIE_NAME  = 'te-admin-bypass';
    public const ADMIN_COOKIE_VALUE = '1';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param ScopeConfigInterface  $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ScopeConfigInterface  $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig  = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    // ──────────────────────────────────────────────
    // General
    // ──────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getCompanyId(): string
    {
        return (string) $this->scopeConfig->getValue(self::COMPANY_ID, ScopeInterface::SCOPE_STORE);
    }

    public function getClientId(): string
    {
        return (string) $this->scopeConfig->getValue(self::CLIENT_ID, ScopeInterface::SCOPE_STORE);
    }

    public function getClientSecret(): string
    {
        return (string) $this->scopeConfig->getValue(self::CLIENT_SECRET, ScopeInterface::SCOPE_STORE);
    }

    // ──────────────────────────────────────────────
    // Cache
    // ──────────────────────────────────────────────

    public function getHtmlTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(self::HTML_TTL, ScopeInterface::SCOPE_STORE)
            ?: self::DEFAULT_HTML_TTL);
    }

    public function getHtmlBrowserTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(self::HTML_BROWSER_TTL, ScopeInterface::SCOPE_STORE)
            ?: self::DEFAULT_HTML_BROWSER_TTL);
    }

    public function getStaticTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(self::STATIC_TTL, ScopeInterface::SCOPE_STORE)
            ?: self::DEFAULT_STATIC_TTL);
    }

    public function getStaticBrowserTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(self::STATIC_BROWSER_TTL, ScopeInterface::SCOPE_STORE)
            ?: self::DEFAULT_STATIC_BROWSER_TTL);
    }

    public function getStaleWhileRevalidate(): int
    {
        return (int) ($this->scopeConfig->getValue(self::STALE_WHILE_REVALIDATE, ScopeInterface::SCOPE_STORE)
            ?: self::DEFAULT_SWR);
    }

    public function getStaleIfError(): int
    {
        return (int) ($this->scopeConfig->getValue(self::STALE_IF_ERROR, ScopeInterface::SCOPE_STORE)
            ?: self::DEFAULT_SIE);
    }

    public function isSoftPurgeEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::SOFT_PURGE, ScopeInterface::SCOPE_STORE);
    }

    public function isRefetchEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::REFETCH, ScopeInterface::SCOPE_STORE);
    }

    // ──────────────────────────────────────────────
    // Warm-up
    // ──────────────────────────────────────────────

    public function isWarmupEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::WARMUP_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function isWarmupHomepage(): bool
    {
        return $this->scopeConfig->isSetFlag(self::WARMUP_HOMEPAGE, ScopeInterface::SCOPE_STORE);
    }

    public function isWarmupCategories(): bool
    {
        return $this->scopeConfig->isSetFlag(self::WARMUP_CATEGORIES, ScopeInterface::SCOPE_STORE);
    }

    public function isWarmupSitemap(): bool
    {
        return $this->scopeConfig->isSetFlag(self::WARMUP_SITEMAP, ScopeInterface::SCOPE_STORE);
    }

    public function getWarmupSitemapUrl(): string
    {
        return (string) $this->scopeConfig->getValue(self::WARMUP_SITEMAP_URL, ScopeInterface::SCOPE_STORE);
    }

    public function getWarmupRateLimit(): int
    {
        return (int) ($this->scopeConfig->getValue(self::WARMUP_RATE_LIMIT, ScopeInterface::SCOPE_STORE)
            ?: self::DEFAULT_WARMUP_RATE);
    }

    public function getWarmupCustomUrls(): array
    {
        $urls = (string) $this->scopeConfig->getValue(self::WARMUP_CUSTOM_URLS, ScopeInterface::SCOPE_STORE);
        if (empty($urls)) {
            return [];
        }
        return array_filter(array_map('trim', explode("\n", $urls)));
    }

    // ──────────────────────────────────────────────
    // i3
    // ──────────────────────────────────────────────

    public function isI3Enabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::I3_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function isI3AutoWebp(): bool
    {
        return $this->scopeConfig->isSetFlag(self::I3_AUTO_WEBP, ScopeInterface::SCOPE_STORE);
    }

    public function getI3Quality(): int
    {
        return (int) ($this->scopeConfig->getValue(self::I3_QUALITY, ScopeInterface::SCOPE_STORE) ?: 85);
    }

    public function getI3MaxWidth(): int
    {
        return (int) ($this->scopeConfig->getValue(self::I3_MAX_WIDTH, ScopeInterface::SCOPE_STORE) ?: 2560);
    }

    // ──────────────────────────────────────────────
    // Advanced
    // ──────────────────────────────────────────────

    public function isAdminBypassEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ADMIN_BYPASS, ScopeInterface::SCOPE_STORE);
    }

    public function isDebugMode(): bool
    {
        return $this->scopeConfig->isSetFlag(self::DEBUG_MODE, ScopeInterface::SCOPE_STORE);
    }

    public function getExcludeUrls(): array
    {
        $urls = (string) $this->scopeConfig->getValue(self::EXCLUDE_URLS, ScopeInterface::SCOPE_STORE);
        if (empty($urls)) {
            return [];
        }
        return array_filter(array_map('trim', explode("\n", $urls)));
    }

    public function getExcludeCookies(): array
    {
        $cookies = (string) $this->scopeConfig->getValue(self::EXCLUDE_COOKIES, ScopeInterface::SCOPE_STORE);
        if (empty($cookies)) {
            return [];
        }
        return array_filter(array_map('trim', explode("\n", $cookies)));
    }

    // ──────────────────────────────────────────────
    // WPO
    // ──────────────────────────────────────────────

    public function isWpoEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::WPO_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function isWpoPreloadEnabled(): bool
    {
        return $this->isWpoEnabled()
            && $this->scopeConfig->isSetFlag(self::WPO_PRELOAD, ScopeInterface::SCOPE_STORE);
    }

    public function isWpoLazyLoadEnabled(): bool
    {
        return $this->isWpoEnabled()
            && $this->scopeConfig->isSetFlag(self::WPO_LAZYLOAD, ScopeInterface::SCOPE_STORE);
    }

    public function isWpoDnsPrefetchEnabled(): bool
    {
        return $this->isWpoEnabled()
            && $this->scopeConfig->isSetFlag(self::WPO_DNS_PREFETCH, ScopeInterface::SCOPE_STORE);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    public function getBaseUrl(): string
    {
        try {
            return rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get the API endpoint for tag invalidation
     */
    public function getInvalidateEndpoint(): string
    {
        return sprintf(
            '%s/%s/companies/%s/tag_invalidate/',
            self::API_BASE_URL,
            self::API_VERSION,
            $this->getCompanyId()
        );
    }

    /**
     * Get the API endpoint for URL invalidation (fallback)
     */
    public function getUrlInvalidateEndpoint(): string
    {
        return sprintf(
            '%s/%s/companies/%s/invalidate/',
            self::API_BASE_URL,
            self::API_VERSION,
            $this->getCompanyId()
        );
    }

    /**
     * Get the OAuth2 token endpoint
     */
    public function getTokenEndpoint(): string
    {
        return sprintf('%s/%s/oauth2/access_token/', self::API_BASE_URL, self::API_VERSION);
    }

    /**
     * Build Cache-Control header value for HTML pages
     */
    public function buildHtmlCacheControl(): string
    {
        $parts = [
            'public',
            sprintf('s-maxage=%d', $this->getHtmlTtl()),
            sprintf('max-age=%d', $this->getHtmlBrowserTtl()),
            sprintf('stale-while-revalidate=%d', $this->getStaleWhileRevalidate()),
            sprintf('stale-if-error=%d', $this->getStaleIfError()),
        ];
        return implode(', ', $parts);
    }

    /**
     * Build Cache-Control header value for static assets
     */
    public function buildStaticCacheControl(): string
    {
        $parts = [
            'public',
            sprintf('s-maxage=%d', $this->getStaticTtl()),
            sprintf('max-age=%d', $this->getStaticBrowserTtl()),
        ];
        return implode(', ', $parts);
    }

    /**
     * Check if all required credentials are configured
     */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && !empty($this->getCompanyId())
            && !empty($this->getClientId())
            && !empty($this->getClientSecret());
    }

    /**
     * Get the dashboard invalidation URL
     */
    public function getDashboardUrl(): string
    {
        return sprintf(
            'https://dashboard.transparentcdn.com/%s/invalidation',
            $this->getCompanyId()
        );
    }
}
