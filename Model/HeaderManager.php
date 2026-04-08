<?php
/**
 * HTTP header manager for cache control and Surrogate-Key injection
 *
 * Adds Cache-Control (s-maxage, stale-while-revalidate) and Surrogate-Key
 * headers to HTTP responses so Transparent Edge CDN (Varnish Enterprise)
 * can cache and invalidate content properly.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class HeaderManager
{
    /**
     * Header names
     */
    private const HEADER_SURROGATE_KEY   = 'Surrogate-Key';
    private const HEADER_CACHE_CONTROL   = 'Cache-Control';
    private const HEADER_MAGENTO_TAGS    = 'X-Magento-Tags';
    private const HEADER_TE_DEBUG        = 'X-TE-Debug';
    private const HEADER_TE_CACHE_STATUS = 'X-TE-Cache';

    /**
     * Static file extensions that should get static TTLs
     */
    private const STATIC_EXTENSIONS = [
        'js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif',
        'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'map',
    ];

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var TagResolver
     */
    private TagResolver $tagResolver;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config           $config
     * @param TagResolver      $tagResolver
     * @param RequestInterface $request
     * @param LoggerInterface  $logger
     */
    public function __construct(
        Config           $config,
        TagResolver      $tagResolver,
        RequestInterface $request,
        LoggerInterface  $logger
    ) {
        $this->config      = $config;
        $this->tagResolver = $tagResolver;
        $this->request     = $request;
        $this->logger      = $logger;
    }

    /**
     * Apply Transparent Edge cache headers to the HTTP response
     *
     * @param  HttpResponse $response
     * @return void
     */
    public function applyHeaders(HttpResponse $response): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // Don't cache non-GET/HEAD requests
        $method = $this->request->getMethod();
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        // Check if this URL is excluded
        if ($this->isExcluded()) {
            $this->setNoCacheHeaders($response);
            return;
        }

        // Apply Surrogate-Key header from Magento tags
        $this->applySurrogateKeyHeader($response);

        // Apply Cache-Control header
        $this->applyCacheControlHeader($response);

        // Debug header
        if ($this->config->isDebugMode()) {
            $this->applyDebugHeaders($response);
        }
    }

    /**
     * Apply Surrogate-Key header based on X-Magento-Tags
     *
     * Magento's built-in FPC writes cache identity tags into X-Magento-Tags.
     * We read those, translate them to TE Surrogate-Keys, and add the header.
     *
     * @param HttpResponse $response
     */
    private function applySurrogateKeyHeader(HttpResponse $response): void
    {
        $magentoTags = $response->getHeader(self::HEADER_MAGENTO_TAGS);

        $tags = [];
        if ($magentoTags) {
            $tagValue = is_object($magentoTags) ? $magentoTags->getFieldValue() : (string) $magentoTags;
            $tags = array_filter(array_map('trim', explode(',', $tagValue)));
        }

        $surrogateKey = $this->tagResolver->buildSurrogateKeyHeader($tags);

        if (!empty($surrogateKey)) {
            $response->setHeader(self::HEADER_SURROGATE_KEY, $surrogateKey, true);
        }
    }

    /**
     * Apply Cache-Control header based on content type
     *
     * Magento's built-in FPC (caching_application=1) sets Cache-Control: no-cache
     * on ALL pages because it handles caching internally. But when we're running
     * behind a CDN, we need to override this with proper TTLs.
     *
     * The presence of X-Magento-Cache-Control means the page IS cacheable by
     * Magento's FPC — so we should always set our own Cache-Control for the CDN.
     *
     * @param HttpResponse $response
     */
    private function applyCacheControlHeader(HttpResponse $response): void
    {
        // If X-Magento-Cache-Control exists, this page is cacheable by Magento's FPC.
        // Override the no-cache that the built-in FPC sets with our CDN TTLs.
        $magentoCacheControl = $response->getHeader('X-Magento-Cache-Control');
        $isMagentoCacheable = ($magentoCacheControl !== false && $magentoCacheControl !== null);

        if (!$isMagentoCacheable) {
            // No X-Magento-Cache-Control = Magento says this is genuinely not cacheable
            // (checkout, customer area, POST responses, etc.)
            // Check if it's already no-cache for a real reason
            $currentCC = $response->getHeader(self::HEADER_CACHE_CONTROL);
            if ($currentCC) {
                $ccValue = is_object($currentCC) ? $currentCC->getFieldValue() : (string) $currentCC;
                if (strpos($ccValue, 'no-cache') !== false || strpos($ccValue, 'no-store') !== false) {
                    return;
                }
            }
        }

        // Apply our CDN Cache-Control headers
        if ($this->isStaticAsset()) {
            $response->setHeader(
                self::HEADER_CACHE_CONTROL,
                $this->config->buildStaticCacheControl(),
                true
            );
        } else {
            $response->setHeader(
                self::HEADER_CACHE_CONTROL,
                $this->config->buildHtmlCacheControl(),
                true
            );
        }
    }

    /**
     * Set no-cache headers for excluded URLs
     *
     * @param HttpResponse $response
     */
    private function setNoCacheHeaders(HttpResponse $response): void
    {
        $response->setHeader(self::HEADER_CACHE_CONTROL, 'no-cache, no-store, must-revalidate', true);
        $response->setHeader(self::HEADER_TE_CACHE_STATUS, 'BYPASS', true);
    }

    /**
     * Add debug headers showing cache tag resolution
     *
     * @param HttpResponse $response
     */
    private function applyDebugHeaders(HttpResponse $response): void
    {
        $magentoTags = $response->getHeader(self::HEADER_MAGENTO_TAGS);
        $tagCount    = 0;

        if ($magentoTags) {
            $tagValue = is_object($magentoTags) ? $magentoTags->getFieldValue() : (string) $magentoTags;
            $tags     = array_filter(array_map('trim', explode(',', $tagValue)));
            $tagCount = count($tags);
        }

        $sk = $response->getHeader(self::HEADER_SURROGATE_KEY);
        $skValue = $sk ? (is_object($sk) ? $sk->getFieldValue() : (string) $sk) : '';
        $skCount = !empty($skValue) ? count(explode(' ', $skValue)) : 0;

        $response->setHeader(
            self::HEADER_TE_DEBUG,
            sprintf('magento_tags=%d surrogate_keys=%d', $tagCount, $skCount),
            true
        );
    }

    /**
     * Check if the current request is for a static asset
     *
     * @return bool
     */
    private function isStaticAsset(): bool
    {
        $uri = $this->request->getRequestUri();
        if (!$uri) {
            return false;
        }

        // Check known static paths
        if (preg_match('#^/static/|^/media/#', $uri)) {
            return true;
        }

        // Check by extension
        $extension = strtolower(pathinfo(parse_url($uri, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        return in_array($extension, self::STATIC_EXTENSIONS, true);
    }

    /**
     * Check if the current URL is in the exclusion list
     *
     * @return bool
     */
    private function isExcluded(): bool
    {
        $uri = $this->request->getRequestUri();
        if (!$uri) {
            return false;
        }

        // Always exclude checkout and customer account areas
        $alwaysExclude = [
            '/checkout/',
            '/customer/',
            '/wishlist/',
            '/multishipping/',
            '/paypal/',
            '/persistent/',
            '/review/product/post/',
            '/newsletter/subscriber/',
            '/sendfriend/',
            '/catalogsearch/result/',
        ];

        foreach ($alwaysExclude as $pattern) {
            if (strpos($uri, $pattern) !== false) {
                return true;
            }
        }

        // Check admin-configured exclusions
        foreach ($this->config->getExcludeUrls() as $pattern) {
            if (empty($pattern)) {
                continue;
            }
            // Support simple wildcards
            $regex = '#' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '#')) . '#i';
            if (preg_match($regex, $uri)) {
                return true;
            }
        }

        return false;
    }
}
