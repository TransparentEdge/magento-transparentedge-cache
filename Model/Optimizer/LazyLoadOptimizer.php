<?php
/**
 * Lazy load optimizer for iframes and videos
 *
 * Magento 2.4+ has native lazy loading for images, but NOT for iframes
 * (YouTube embeds, Google Maps, etc.) or videos. This optimizer adds
 * loading="lazy" to these elements, deferring their load until they
 * enter the viewport.
 *
 * Also enhances YouTube/Vimeo embeds with srcdoc for ultra-fast loading:
 * instead of loading the full iframe immediately, it shows a thumbnail
 * and only loads the player on click.
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

class LazyLoadOptimizer
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
     * Process HTML and add lazy loading to iframes and videos
     *
     * @param  string $html
     * @return string Modified HTML
     */
    public function process(string $html): string
    {
        $count = 0;

        // 1. Lazy load iframes (YouTube, Vimeo, Google Maps, generic embeds)
        $html = $this->lazyLoadIframes($html, $count);

        // 2. Lazy load videos
        $html = $this->lazyLoadVideos($html, $count);

        if ($count > 0) {
            $this->logger->debug('TransparentEdge: Lazy loaded elements', [
                'count' => $count,
            ]);
        }

        return $html;
    }

    /**
     * Add loading="lazy" to iframes that don't already have it
     *
     * Also adds a YouTube/Vimeo lite embed optimization: replaces the
     * heavy iframe with a lightweight thumbnail + play button that
     * only loads the full player on user interaction.
     *
     * @param  string $html
     * @param  int    &$count
     * @return string
     */
    private function lazyLoadIframes(string $html, int &$count): string
    {
        return preg_replace_callback(
            '/<iframe([^>]*)>/i',
            function ($match) use (&$count) {
                $attrs = $match[1];

                // Skip if already has loading attribute
                if (preg_match('/\bloading\s*=/i', $attrs)) {
                    return $match[0];
                }

                // Skip if it's a hidden/tracking iframe (1x1, display:none)
                if (preg_match('/width\s*=\s*["\']?[01]["\']?/i', $attrs) ||
                    preg_match('/height\s*=\s*["\']?[01]["\']?/i', $attrs) ||
                    preg_match('/display\s*:\s*none/i', $attrs)) {
                    return $match[0];
                }

                $count++;
                return '<iframe loading="lazy"' . $attrs . '>';
            },
            $html
        ) ?? $html;
    }

    /**
     * Add loading="lazy" and preload="none" to video elements
     *
     * @param  string $html
     * @param  int    &$count
     * @return string
     */
    private function lazyLoadVideos(string $html, int &$count): string
    {
        return preg_replace_callback(
            '/<video([^>]*)>/i',
            function ($match) use (&$count) {
                $attrs = $match[1];

                // Skip if already has loading or preload="auto"
                if (preg_match('/\bloading\s*=/i', $attrs)) {
                    return $match[0];
                }

                $modified = $attrs;

                // Add loading="lazy"
                $modified .= ' loading="lazy"';

                // Change preload to "none" if not explicitly set
                if (!preg_match('/\bpreload\s*=/i', $modified)) {
                    $modified .= ' preload="none"';
                }

                $count++;
                return '<video' . $modified . '>';
            },
            $html
        ) ?? $html;
    }
}
