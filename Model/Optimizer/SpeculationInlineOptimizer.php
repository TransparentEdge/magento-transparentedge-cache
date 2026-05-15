<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\Optimizer;

use TransparentEdge\CDN\Model\Config;
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

    public function __construct(Config $config, ExclusionCollector $exclusionCollector, LoggerInterface $logger)
    {
        $this->config             = $config;
        $this->exclusionCollector = $exclusionCollector;
        $this->logger             = $logger;
    }

    public function process(string $html): string
    {
        if (!$this->config->isSpeculationEnabled()) {
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
        if (preg_match_all('/<a[^>]+href="([^"#]+)"/i', $html, $m)) {
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

    private function isValidUrl(string $url): bool
    {
        if (empty($url) || $url === '#' || strpos($url, 'javascript:') === 0) return false;
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        foreach ($this->exclusionCollector->getPathExclusions() as $excl) {
            $pattern = str_replace('*', '.*', preg_quote($excl, '#'));
            if (preg_match('#^' . $pattern . '$#i', $path)) return false;
        }
        return true;
    }
}
