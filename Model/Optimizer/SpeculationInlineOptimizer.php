<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\Optimizer;

use Magento\Framework\App\CacheInterface;
use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Diagnostics;
use TransparentEdge\CDN\Model\SpeculationRules\ExclusionCollector;
use Psr\Log\LoggerInterface;

class SpeculationInlineOptimizer
{
    private const MAX_PLP_PRODUCTS = 3;
    private const MAX_RELATED      = 4;
    private const MAX_MENU_CATS    = 5;
    private const MAX_CMS_LINKS    = 5;

    private Config $config;
    private ExclusionCollector $exclusionCollector;
    private LoggerInterface $logger;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    public function __construct(
        Config             $config,
        ExclusionCollector $exclusionCollector,
        LoggerInterface    $logger,
        CacheInterface     $cache
    ) {
        $this->config             = $config;
        $this->exclusionCollector = $exclusionCollector;
        $this->logger             = $logger;
        $this->cache              = $cache;
    }

    public function process(string $html): string
    {
        if (!$this->config->isSpeculationEnabled()) {
            return $html;
        }

        // Somebody else is already telling the browser what to prefetch.
        // Adding ours on top does not override theirs — the browser merges
        // every rule set on the page — so the visitor ends up fetching both
        // lists. That is bandwidth on their connection and requests against
        // the origin for pages nobody asked for, and the two sets will
        // disagree about what to exclude.
        $conflicto = $this->detectConflict($html);
        // Recorded either way. An absent reading has to mean "no page has
        // been rendered since the last flush" and nothing else — if it also
        // meant "no conflict", a check that never ran would be
        // indistinguishable from one that passed.
        $this->recordConflict($conflicto ?? '');
        if ($conflicto !== null) {
            return $html;
        }

        $pageType = $this->detectPageType($html);
        if ($pageType === null) {
            return $html;
        }

        $urls = $this->extractUrlsForPageType($html, $pageType);
        if (empty($urls)) {
            return $html;
        }

        $rules = $this->buildInlineRules($urls, $pageType, $this->config->getSpeculationMode());
        if (empty($rules)) {
            return $html;
        }

        $json = json_encode($rules, JSON_UNESCAPED_SLASHES);
        $script = "\n    <!-- TE CDN: Contextual Speculation Rules ({$pageType}) -->\n"
                . "    <script type=\"speculationrules\">{$json}</script>\n";

        $headPos = stripos($html, '</head>');
        if ($headPos !== false) {
            $html = substr($html, 0, $headPos) . $script . substr($html, $headPos);
        }

        if ($this->config->shouldLog('debug')) {
            $this->logger->debug('TransparentEdge: Inline speculation rules injected', [
                'page_type' => $pageType, 'urls' => count($urls),
            ]);
        }

        return $html;
    }

    /**
     * Known prefetch libraries, by the name they appear under in a script.
     *
     * Detected by behaviour rather than by a list of module names: the
     * WordPress plugin can check a list of known plugins because it can read
     * the active-plugin list, and the names there are stable. There is no
     * equivalent list for Magento that could be written without guessing, so
     * this looks at what the page actually does. These are real libraries,
     * each of which prefetches links on its own.
     */
    private const KNOWN_PREFETCHERS = ['quicklink', 'instant.page', 'instantpage', 'flying-pages'];

    /**
     * What, if anything, is already speculating on this page.
     *
     * @return string|null Human-readable name of the conflict, or null.
     */
    private function detectConflict(string $html): ?string
    {
        // Our own marker: the page came through here already, or the edge
        // injected the rules. Not a conflict with a third party.
        if (strpos($html, 'TE CDN: Contextual Speculation Rules') !== false) {
            return null;
        }

        if (preg_match('/<script[^>]+type\s*=\s*["\']speculationrules["\']/i', $html) === 1) {
            return 'another speculationrules script';
        }

        foreach (self::KNOWN_PREFETCHERS as $nombre) {
            // Only inside a script tag: the word could appear in page copy.
            $patron = '/<script[^>]*(?:src\s*=\s*["\'][^"\']*' . preg_quote($nombre, '/') . '|[^>]*>[^<]{0,2000}'
                . preg_quote($nombre, '/') . ')/i';
            if (preg_match($patron, $html) === 1) {
                return $nombre;
            }
        }

        return null;
    }

    /**
     * Leave the finding where Diagnostics can report it.
     *
     * Same shape as the cache-blocker probe: written from real traffic, read
     * by the admin panel, /health and the CLI. Rate-limited the same way, so
     * a busy storefront does not write on every render.
     */
    private function recordConflict(string $que): void
    {
        try {
            $previo = $this->cache->load(Diagnostics::SPECULATION_CONFLICT_KEY);
            $previo = is_string($previo) && $previo !== '' ? json_decode($previo, true) : null;

            if (is_array($previo)
                && ($previo['what'] ?? null) === $que
                && (time() - (int) ($previo['at'] ?? 0)) < 3600) {
                return;
            }

            $this->cache->save(
                (string) json_encode(['at' => time(), 'what' => $que]),
                Diagnostics::SPECULATION_CONFLICT_KEY,
                ['TE_CDN_DIAGNOSTICS'],
                172800
            );
        } catch (\Exception $e) {
            $this->logger->debug('TransparentEdge: could not record speculation conflict: ' . $e->getMessage());
        }
    }

    private function detectPageType(string $html): ?string
    {
        if (!preg_match('/<body[^>]+class="([^"]+)"/i', $html, $match)) {
            return null;
        }
        $bc = $match[1];
        if (strpos($bc, 'cms-home') !== false) return 'homepage';
        if (strpos($bc, 'catalog-category-view') !== false) return 'plp';
        if (strpos($bc, 'catalog-product-view') !== false) return 'pdp';
        if (strpos($bc, 'cms-page-view') !== false) return 'cms';
        return null;
    }

    private function extractUrlsForPageType(string $html, string $pageType): array
    {
        switch ($pageType) {
            case 'homepage': return $this->extractMenuCategoryUrls($html);
            case 'plp':      return $this->extractProductListUrls($html);
            case 'pdp':      return $this->extractRelatedUrls($html);
            case 'cms':      return $this->extractInternalLinks($html);
            default:         return [];
        }
    }

    private function extractMenuCategoryUrls(string $html): array
    {
        $urls = [];
        if (preg_match_all('/<li[^>]*class="[^"]*level0[^"]*"[^>]*>.*?<a[^>]+href="([^"]+)"[^>]*>/si', $html, $m)) {
            foreach ($m[1] as $url) {
                if ($this->isValidUrl($url)) $urls[] = $url;
            }
        }
        return array_slice(array_unique($urls), 0, self::MAX_MENU_CATS);
    }

    private function extractProductListUrls(string $html): array
    {
        $urls = [];
        if (preg_match_all('/<a[^>]+class="[^"]*product-item-link[^"]*"[^>]+href="([^"]+)"/i', $html, $m)) {
            foreach ($m[1] as $url) {
                if ($this->isValidUrl($url)) $urls[] = $url;
            }
        }
        return array_slice(array_unique($urls), 0, self::MAX_PLP_PRODUCTS);
    }

    private function extractRelatedUrls(string $html): array
    {
        $urls = [];
        if (preg_match_all('/<li[^>]*class="[^"]*item category[^"]*"[^>]*>.*?<a[^>]+href="([^"]+)"/si', $html, $m)) {
            foreach ($m[1] as $url) {
                if ($this->isValidUrl($url)) $urls[] = $url;
            }
        }
        if (preg_match_all('/<div[^>]*class="[^"]*(?:related|upsell|crosssell)[^"]*".*?<a[^>]+href="([^"]+)"[^>]+class="[^"]*product-item-link/si', $html, $m)) {
            foreach ($m[1] as $url) {
                if ($this->isValidUrl($url)) $urls[] = $url;
            }
        }
        return array_slice(array_unique($urls), 0, self::MAX_RELATED);
    }

    private function extractInternalLinks(string $html): array
    {
        $urls = [];
        $baseHost = parse_url($this->config->getBaseUrl(), PHP_URL_HOST) ?: '';

        // Restrict to the main content area. Scanning the whole document would
        // pick up header/footer navigation, which in Magento includes account,
        // cart, wishlist and logout links.
        $content = $html;
        if (preg_match('/<main\b[^>]*>(.*?)<\/main>/si', $html, $mainMatch)) {
            $content = $mainMatch[1];
        } elseif (preg_match('/<div[^>]*class="[^"]*(?:column main|cms-content|page-main)[^"]*"[^>]*>(.*)$/si', $html, $cMatch)) {
            $content = $cMatch[1];
        }

        if (preg_match_all('/<a[^>]+href="([^"#]+)"/i', $content, $m)) {
            foreach ($m[1] as $url) {
                $urlHost = parse_url($url, PHP_URL_HOST);
                if ($urlHost && $urlHost !== $baseHost) continue;
                if ($this->isValidUrl($url)) $urls[] = $url;
            }
        }
        return array_slice(array_unique($urls), 0, self::MAX_CMS_LINKS);
    }

    private function buildInlineRules(array $urls, string $pageType, string $mode): array
    {
        if ($pageType === 'plp' && in_array($mode, ['balanced', 'aggressive'], true)) {
            return ['prerender' => [['source' => 'list', 'urls' => $urls, 'eagerness' => 'moderate']]];
        }
        if ($pageType === 'homepage' && $mode === 'aggressive') {
            return ['prerender' => [['source' => 'list', 'urls' => $urls, 'eagerness' => 'conservative']]];
        }
        return ['prefetch' => [['source' => 'list', 'urls' => $urls, 'eagerness' => 'immediate']]];
    }

    /**
     * Path segments that must NEVER be speculatively fetched, checked as a
     * plain substring as a second line of defence behind the glob exclusions.
     *
     * These endpoints mutate state on GET in Magento (wishlist/compare adds,
     * logout, cart adds), so a speculative fetch would have real side effects
     * for the visitor.
     */
    private const HARD_BLOCK = [
        '/checkout/', '/customer/', '/wishlist/', '/catalog/product_compare/',
        '/paypal/', '/braintree/', '/rest/', '/graphql', '/admin',
        '/newsletter/', '/review/product/post/', '/sendfriend/',
        '/downloadable/', '/sales/', '/multishipping/', '/vault/',
        '/instant-purchase/', '/persistent/', '/catalogsearch/',
        '/directory/currency/', '/stores/store/switch',
    ];

    private function isValidUrl(string $url): bool
    {
        if (empty($url) || $url === '#' || strpos($url, 'javascript:') === 0) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $query = parse_url($url, PHP_URL_QUERY);
        $pathAndQuery = $query !== null ? $path . '?' . $query : $path;

        // Layer 1: hard substring blocklist (cannot be defeated by pattern bugs)
        foreach (self::HARD_BLOCK as $blocked) {
            if (stripos($path, $blocked) !== false) {
                return false;
            }
        }

        // Layer 2: glob exclusions from ExclusionCollector.
        // Split on '*' BEFORE quoting: preg_quote() escapes '*' to '\*', so
        // quoting first and then replacing '*' with '.*' produces '\.*'
        // (zero-or-more literal dots) instead of a wildcard.
        foreach ($this->exclusionCollector->getPathExclusions() as $excl) {
            $pattern = implode('.*', array_map(
                static function (string $part): string {
                    return preg_quote($part, '#');
                },
                explode('*', $excl)
            ));
            if (preg_match('#^' . $pattern . '$#i', $pathAndQuery)
                || preg_match('#^' . $pattern . '$#i', $path)) {
                return false;
            }
        }

        return true;
    }
}
