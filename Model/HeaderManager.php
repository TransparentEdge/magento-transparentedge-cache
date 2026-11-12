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

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class HeaderManager
{
    /**
     * Header names
     */
    private const HEADER_SURROGATE_KEY   = 'Surrogate-Keys';
    private const HEADER_CACHE_CONTROL   = 'Cache-Control';
    private const HEADER_MAGENTO_TAGS    = 'X-Magento-Tags';
    private const HEADER_TE_DEBUG        = 'X-TE-Debug';
    private const HEADER_TE_CACHE_STATUS = 'X-TE-Cache';

    /** Debug-only: which cache profile answered this request. */
    private const HEADER_TE_PAGE_TYPE    = 'X-TE-Page-Type';

    /**
     * Internal marker set by our own controllers.
     *
     * A controller of ours that has already chosen its Surrogate-Keys and its
     * TTL on purpose (the Speculation Rules endpoint tags itself so it can be
     * invalidated on its own; the health endpoint must never be cached) sets
     * this header. applyHeaders() then leaves the response exactly as it is
     * and strips the marker, so it never reaches the client.
     *
     * This is a marker rather than a check on the Cache-Control value because
     * Magento itself emits s-maxage in some configurations: sniffing the value
     * would make the plugin stop setting TTLs on ordinary pages.
     */
    public const HEADER_TE_OWN = 'X-TE-Own-Headers';

    /**
     * How long a cache-blocker reading stays in the cache. Longer than the
     * window Diagnostics trusts it for, so a stale one is reported as stale
     * rather than disappearing and looking like it was never taken.
     */
    private const CACHE_BLOCKERS_TTL = 172800;

    /**
     * The only token an anonymous cacheable page needs to vary on.
     *
     * Magento sends this by itself today. The list exists so that what we
     * send is decided here and not by whatever the last module to touch the
     * header happened to want.
     */
    private const VARY_BASE = ['Accept-Encoding'];

    /**
     * Never varied on, whoever asks.
     *
     * Each of these takes a value that is close to unique per visitor, so the
     * edge ends up storing one copy of the page per client and serving almost
     * none of them twice. `*` tells caches the response is not reusable at
     * all. None of them maps to a real anonymous variant: what legitimately
     * varies a Magento page (store, currency, customer group) travels in the
     * X-Magento-Vary cookie, which the VCL turns into the edge's own key.
     */
    private const VARY_FORBIDDEN = ['cookie', 'user-agent', '*'];

    /**
     * header_register_callback takes one callback per request, so registering
     * twice would silently drop the first.
     *
     * @var bool
     */
    private bool $varyCallbackSet = false;

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
     * @var CacheProfile
     */
    private CacheProfile $cacheProfile;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @param Config           $config
     * @param TagResolver      $tagResolver
     * @param RequestInterface $request
     * @param LoggerInterface  $logger
     * @param CacheProfile     $cacheProfile
     */
    public function __construct(
        Config           $config,
        TagResolver      $tagResolver,
        RequestInterface $request,
        LoggerInterface  $logger,
        CacheProfile     $cacheProfile,
        CacheInterface   $cache
    ) {
        $this->config       = $config;
        $this->tagResolver  = $tagResolver;
        $this->request      = $request;
        $this->logger       = $logger;
        $this->cacheProfile = $cacheProfile;
        $this->cache        = $cache;
    }

    /**
     * Apply Transparent Edge cache headers to the HTTP response
     *
     * @param  HttpResponse $response
     * @return void
     */
    public function applyHeaders(HttpResponse $response): void
    {
        try {
            $this->applyHeadersInternal($response);
        } catch (\Throwable $e) {
            // Log where the failure actually happened, with the logger this
            // object already holds, then let the response through untouched.
            $this->logger->warning('TransparentEdge: could not apply cache headers', [
                'exception' => get_class($e),
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  HttpResponse $response
     * @return void
     */
    private function applyHeadersInternal(HttpResponse $response): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // Don't cache non-GET/HEAD requests
        $method = $this->request->getMethod();
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        // One of our own controllers already set its headers deliberately.
        // Strip the marker and leave the response untouched.
        if ($response->getHeader(self::HEADER_TE_OWN)) {
            $response->clearHeader(self::HEADER_TE_OWN);
            return;
        }

        // Never let an error response be cached at the edge. A transient 5xx
        // (an upstream blip, a 503 from maintenance mode) pinned for the full
        // s-maxage — 48h by default — keeps the site down long after it
        // recovered. 404 is the one error worth caching, and the generated VCL
        // gives it its own short TTL.
        $status = (int) $response->getHttpResponseCode();
        if ($status >= 400 && $status !== 404) {
            $this->setNoCacheHeaders($response);
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

        // We have just declared this response cacheable. Look at what it is
        // actually carrying before it leaves — before the next line tidies
        // the Vary, so Diagnostics still reports that something was adding a
        // bad token rather than quietly covering for it.
        $this->probeCacheBlockers($response);

        // Decide the Vary ourselves instead of inheriting it.
        $this->applySafeVary($response);

        // Debug header
        if ($this->config->isDebugMode()) {
            $this->applyDebugHeaders($response);
        } else {
            // In production, remove internal Magento headers to avoid exposing
            // catalog structure and cache configuration to the outside
            $response->clearHeader(self::HEADER_MAGENTO_TAGS);
            $response->clearHeader('X-Magento-Cache-Control');
        }

        // Speculation Rules header (only for HTML responses via PHP injection)
        $this->applySpeculationRulesHeader($response);
    }

    /**
     * Inject Speculation-Rules header pointing to the JSON endpoint.
     *
     * Only applied when:
     * - Speculation Rules is enabled
     * - Injection mode is "php" (not "vcl", which handles it at the edge)
     * - Response is HTML (not static assets, API, or AJAX)
     *
     * @param HttpResponse $response
     */
    private function applySpeculationRulesHeader(HttpResponse $response): void
    {
        if (!$this->config->isSpeculationEnabled()) {
            return;
        }

        if ($this->config->getSpeculationInjection() !== 'php') {
            return;
        }

        // Only inject in HTML responses
        $contentType = $response->getHeader('Content-Type');
        if ($contentType) {
            $ctValue = is_object($contentType) ? $contentType->getFieldValue() : (string) $contentType;
            if (stripos($ctValue, 'text/html') === false) {
                return;
            }
        }

        // Don't inject in admin, AJAX, or excluded paths
        $uri = $this->request->getRequestUri();
        if ($uri && preg_match('#^/admin|^/rest/|^/graphql|^/checkout/#i', $uri)) {
            return;
        }

        $rulesUrl = $this->config->getSpeculationRulesUrl();
        $response->setHeader('Speculation-Rules', '"' . $rulesUrl . '"', true);
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

        if (empty($surrogateKey)) {
            return;
        }

        // Never destroy keys another layer already set: a module (or a future
        // controller of ours without the marker above) may have tagged this
        // response for its own surgical invalidation. Merge, don't replace.
        $existing = $response->getHeader(self::HEADER_SURROGATE_KEY);
        if ($existing) {
            $existingValue = is_object($existing) ? $existing->getFieldValue() : (string) $existing;
            $merged = array_filter(
                array_map('trim', explode(' ', $existingValue . ' ' . $surrogateKey)),
                static fn(string $k): bool => $k !== ''
            );
            $merged = array_unique($merged);
            sort($merged);
            $surrogateKey = implode(' ', $merged);
        }

        $response->setHeader(self::HEADER_SURROGATE_KEY, $surrogateKey, true);
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
    /**
     * Keep the tokens worth varying on and drop the ones that break caching.
     *
     * Merges rather than replaces: a module may have added a token that is
     * perfectly reasonable, and throwing it away would serve the wrong
     * variant to somebody. Only the forbidden ones are removed.
     *
     * @param  array<string> $tokens
     * @return array<string>
     */
    private static function safeVaryTokens(array $tokens): array
    {
        $salida = self::VARY_BASE;

        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if ($t === '' || in_array(strtolower($t), self::VARY_FORBIDDEN, true)) {
                continue;
            }
            $salida[] = $t;
        }

        return array_values(array_unique($salida));
    }

    /**
     * Every Vary token already set, from both places one can come from.
     *
     * @return array<string>
     */
    private static function currentVaryTokens(?HttpResponse $response = null): array
    {
        $crudo = [];

        if (function_exists('headers_list')) {
            foreach (headers_list() as $linea) {
                if (stripos($linea, 'vary:') === 0) {
                    $crudo[] = trim(substr($linea, 5));
                }
            }
        }

        if ($response !== null) {
            $v = $response->getHeader('Vary');
            if ($v) {
                $crudo[] = (string) $v->getFieldValue();
            }
        }

        $tokens = [];
        foreach ($crudo as $valor) {
            foreach (explode(',', $valor) as $t) {
                $tokens[] = $t;
            }
        }

        return $tokens;
    }

    /**
     * Put a safe Vary on a cacheable response, and keep it that way.
     *
     * Setting it here is not enough on its own. Magento, a theme or a
     * third-party module can touch the header after this runs and before the
     * response is sent, and a single stray `Vary: Cookie` added at that point
     * costs the whole page cache — the object is stored, keyed per visitor,
     * and served to nobody twice.
     *
     * So the value is also re-asserted from a header_register_callback, which
     * PHP runs at the moment headers go out, after everything else has had
     * its turn. The callback recomputes from whatever is set by then rather
     * than replaying the value captured here, so a legitimate token added
     * late still survives.
     *
     * Only cacheable responses reach this: a passed or personalized response
     * is left exactly as its own code wrote it.
     */
    private function applySafeVary(HttpResponse $response): void
    {
        try {
            $vary = self::safeVaryTokens(self::currentVaryTokens($response));
            $response->setHeader('Vary', implode(', ', $vary), true);

            if ($this->varyCallbackSet || !function_exists('header_register_callback')) {
                return;
            }
            $this->varyCallbackSet = true;

            header_register_callback(static function (): void {
                if (!function_exists('header')) {
                    return;
                }
                $vary = self::safeVaryTokens(self::currentVaryTokens());
                header('Vary: ' . implode(', ', $vary), true);
            });
        } catch (\Exception $e) {
            $this->logger->debug('TransparentEdge: could not normalise Vary: ' . $e->getMessage());
        }
    }

    /**
     * Record what a cacheable response is carrying, for Diagnostics to read.
     *
     * Looks at PHP's own outgoing header list, not just the response object.
     * That is the whole point: Magento's session cookie is written with
     * setcookie() by the session manager, so it never appears in
     * $response->getHeader('Set-Cookie'). A probe that only asked the
     * response object would have reported everything clean while the site
     * cached nothing — exactly the blind spot this is here to remove.
     *
     * Cookie NAMES only. A session id is a credential; storing one in a
     * diagnostics record that is shown in the admin and on a public health
     * endpoint would be handing it out.
     */
    private function probeCacheBlockers(HttpResponse $response): void
    {
        try {
            $cookies = [];
            $vary    = '';

            if (function_exists('headers_list')) {
                foreach (headers_list() as $linea) {
                    $p = strpos($linea, ':');
                    if ($p === false) {
                        continue;
                    }
                    $nombre = strtolower(trim(substr($linea, 0, $p)));
                    $valor  = trim(substr($linea, $p + 1));

                    if ($nombre === 'set-cookie') {
                        $eq = strpos($valor, '=');
                        $cookies[] = $eq === false ? $valor : substr($valor, 0, $eq);
                    } elseif ($nombre === 'vary') {
                        $vary = $valor;
                    }
                }
            }

            // The response object may carry its own, set by Magento or by a
            // module, and those are not in headers_list() yet.
            $v = $response->getHeader('Vary');
            if ($v && $vary === '') {
                $vary = (string) $v->getFieldValue();
            }
            $sc = $response->getHeader('Set-Cookie');
            if ($sc) {
                $valor = (string) $sc->getFieldValue();
                $eq = strpos($valor, '=');
                $cookies[] = $eq === false ? $valor : substr($valor, 0, $eq);
            }

            $cookies = array_values(array_unique(array_filter($cookies)));

            // One cache write per request would cost more than the reading is
            // worth. Write when the picture changes, and otherwise refresh it
            // at most once an hour so a stale reading never speaks for a site
            // that has since been fixed.
            $previo = $this->cache->load(Diagnostics::CACHE_BLOCKERS_KEY);
            $previo = is_string($previo) && $previo !== '' ? json_decode($previo, true) : null;
            $igual  = is_array($previo)
                && ($previo['cookies'] ?? null) === $cookies
                && ($previo['vary'] ?? null) === $vary;

            if ($igual && (time() - (int) ($previo['at'] ?? 0)) < 3600) {
                return;
            }

            $this->cache->save(
                (string) json_encode(['at' => time(), 'cookies' => $cookies, 'vary' => $vary]),
                Diagnostics::CACHE_BLOCKERS_KEY,
                ['TE_CDN_DIAGNOSTICS'],
                self::CACHE_BLOCKERS_TTL
            );
        } catch (\Exception $e) {
            // A diagnostic must never be the reason a page fails to render.
            $this->logger->debug('TransparentEdge: cache-blocker probe failed: ' . $e->getMessage());
        }
    }

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
            // Per-page-type profile. Falls back to the module-wide HTML
            // profile when profiles are off or the page type is not one we
            // treat specially, so behaviour is unchanged until it is enabled.
            $response->setHeader(
                self::HEADER_CACHE_CONTROL,
                $this->cacheProfile->buildHtmlCacheControl(),
                true
            );

            if ($this->config->isDebugMode()) {
                $response->setHeader(self::HEADER_TE_PAGE_TYPE, $this->cacheProfile->getPageType(), true);
            }
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
