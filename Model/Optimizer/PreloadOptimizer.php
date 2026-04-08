<?php
/**
 * Preload optimizer — Critical resource preloading
 *
 * Analyzes the HTML response and injects <link rel="preload"> hints
 * for critical resources:
 * - LCP image: first large image in the viewport (hero/banner)
 * - Critical CSS: the main stylesheet
 * - Critical fonts: web fonts used above the fold
 *
 * These hints tell the browser to start downloading critical resources
 * immediately, before the parser reaches them in the HTML.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\Optimizer;

use TransparentEdge\CDN\Model\Config;
use Psr\Log\LoggerInterface;

class PreloadOptimizer
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config          $config
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Process HTML and inject preload hints
     *
     * @param  string $html
     * @return string Modified HTML
     */
    public function process(string $html): string
    {
        $preloads = [];

        // 1. Detect and preload LCP image (first large image in the page)
        $lcp = $this->detectLcpImage($html);
        if ($lcp) {
            $preloads[] = sprintf(
                '<link rel="preload" href="%s" as="image" fetchpriority="high"/>',
                htmlspecialchars($lcp, ENT_QUOTES, 'UTF-8')
            );
        }

        // 2. Detect and preload critical CSS (main stylesheet)
        $criticalCss = $this->detectCriticalCss($html);
        foreach ($criticalCss as $css) {
            $preloads[] = sprintf(
                '<link rel="preload" href="%s" as="style"/>',
                htmlspecialchars($css, ENT_QUOTES, 'UTF-8')
            );
        }

        // 3. Detect and preload web fonts (woff2 only — most efficient)
        $fonts = $this->detectCriticalFonts($html);
        foreach ($fonts as $font) {
            $preloads[] = sprintf(
                '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin/>',
                htmlspecialchars($font, ENT_QUOTES, 'UTF-8')
            );
        }

        if (empty($preloads)) {
            return $html;
        }

        // Inject preloads right after <head> or after <meta charset>
        $preloadHtml = "\n    <!-- TE CDN: Critical Resource Preloads -->\n    "
            . implode("\n    ", $preloads) . "\n";

        // Try to inject after the first <meta> tag in <head>
        if (preg_match('/<meta[^>]*charset[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $pos = $match[0][1] + strlen($match[0][0]);
            $html = substr($html, 0, $pos) . $preloadHtml . substr($html, $pos);
        } elseif (($headPos = stripos($html, '<head>')) !== false) {
            $pos = $headPos + 6;
            $html = substr($html, 0, $pos) . $preloadHtml . substr($html, $pos);
        }

        $this->logger->debug('TransparentEdge: Injected preload hints', [
            'count' => count($preloads),
            'lcp'   => $lcp ?: 'none',
        ]);

        return $html;
    }

    /**
     * Detect the likely LCP image
     *
     * Looks for the first large image that's likely above the fold:
     * - First <img> in a hero/banner/slider container
     * - First <img> with a product image path
     * - Falls back to the first <img> that's not a logo/icon
     *
     * @param  string $html
     * @return string|null Image URL or null
     */
    private function detectLcpImage(string $html): ?string
    {
        // Skip if page has no images
        if (stripos($html, '<img') === false) {
            return null;
        }

        // Strategy 1: Look for hero/banner/slider images
        $heroPatterns = [
            '/<(?:div|section|figure)[^>]*class="[^"]*(?:hero|banner|slider|carousel|pagebuilder-banner)[^"]*"[^>]*>.*?<img[^>]+src="([^"]+)"/si',
            '/<(?:div|section)[^>]*class="[^"]*(?:block-promo|widget)[^"]*"[^>]*>.*?<img[^>]+src="([^"]+)"/si',
        ];

        foreach ($heroPatterns as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $url = $match[1];
                if ($this->isLargeImage($url)) {
                    return $url;
                }
            }
        }

        // Strategy 2: First catalog product image on product pages
        if (preg_match('/<img[^>]+class="[^"]*(?:gallery-placeholder__image|product-image-photo)[^"]*"[^>]+src="([^"]+)"/i', $html, $match)) {
            return $match[1];
        }

        // Strategy 3: First non-trivial image in the content area
        if (preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if ($this->isLargeImage($url)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Check if a URL is likely a large image (not an icon/logo/tracker)
     *
     * @param  string $url
     * @return bool
     */
    private function isLargeImage(string $url): bool
    {
        // Skip tracking pixels, icons, logos, placeholders
        $skipPatterns = [
            '/\b(icon|logo|pixel|tracker|spacer|placeholder|loading|spinner)\b/i',
            '/\.(gif|svg)$/i',                  // GIFs and SVGs are rarely LCP
            '/data:image/i',                     // Data URIs
            '/1x1|transparent\.png/i',           // Tracking pixels
            '/gravatar\.com/i',                  // Avatars
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return false;
            }
        }

        // Must be a real image
        return (bool) preg_match('/\.(jpe?g|png|webp|avif)(\?.*)?$/i', $url);
    }

    /**
     * Detect critical CSS (main stylesheet)
     *
     * @param  string $html
     * @return array CSS URLs to preload (max 2)
     */
    private function detectCriticalCss(string $html): array
    {
        $cssUrls = [];

        // Look for Magento's main stylesheets
        if (preg_match_all('/<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"[^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                // Prioritize merged/minified CSS and styles-l/styles-m
                if (preg_match('/styles-(l|m)\.css|merged|critical/i', $url)) {
                    $cssUrls[] = $url;
                }
            }
        }

        // If no specific match, take the first stylesheet
        if (empty($cssUrls) && !empty($matches[1])) {
            $cssUrls[] = $matches[1][0];
        }

        // Max 2 CSS preloads to avoid over-hinting
        return array_slice($cssUrls, 0, 2);
    }

    /**
     * Detect critical web fonts referenced in CSS
     *
     * Only preloads .woff2 fonts found directly in the HTML (inline styles
     * or preload hints that already exist). We don't parse external CSS files.
     *
     * @param  string $html
     * @return array Font URLs to preload (max 3)
     */
    private function detectCriticalFonts(string $html): array
    {
        $fonts = [];

        // Look for woff2 font URLs referenced in the HTML
        if (preg_match_all('/url\(["\']?([^"\')\s]+\.woff2)["\']?\)/i', $html, $matches)) {
            $fonts = array_unique($matches[1]);
        }

        // Also check for existing font preloads (don't duplicate)
        $existingPreloads = [];
        if (preg_match_all('/<link[^>]+rel="preload"[^>]+href="([^"]+\.woff2)"[^>]*>/i', $html, $existing)) {
            $existingPreloads = $existing[1];
        }

        $fonts = array_diff($fonts, $existingPreloads);

        // Max 3 fonts to avoid over-preloading
        return array_slice(array_values($fonts), 0, 3);
    }
}
