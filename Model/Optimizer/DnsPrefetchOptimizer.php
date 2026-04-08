<?php
/**
 * DNS Prefetch optimizer
 *
 * Scans the HTML for external domain references (scripts, stylesheets,
 * images, fonts, iframes) and injects <link rel="dns-prefetch"> and
 * <link rel="preconnect"> hints in the <head>.
 *
 * DNS prefetch resolves the domain name in the background before the
 * resource is needed. Preconnect goes further: DNS + TCP + TLS handshake.
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

class DnsPrefetchOptimizer
{
    /**
     * Domains that are always worth preconnecting (high-priority third parties)
     */
    private const PRECONNECT_DOMAINS = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'www.google-analytics.com',
        'www.googletagmanager.com',
        'connect.facebook.net',
    ];

    /**
     * Domains to exclude (same-origin, CDN itself, tracking pixels)
     */
    private const EXCLUDE_PATTERNS = [
        '/^localhost$/i',
        '/^127\.0\.0\.1$/',
        '/transparentcdn\.com$/i',
        '/transparentedge\.(eu|io)$/i',
    ];

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
     * Process HTML and inject DNS prefetch/preconnect hints
     *
     * @param  string $html
     * @return string Modified HTML
     */
    public function process(string $html): string
    {
        // Get the site's own domain to exclude
        $ownHost = parse_url($this->config->getBaseUrl(), PHP_URL_HOST) ?: '';

        // Extract all external domains from the HTML
        $domains = $this->extractExternalDomains($html, $ownHost);

        if (empty($domains)) {
            return $html;
        }

        // Check which hints already exist in the HTML
        $existingHints = $this->getExistingHints($html);

        // Build preconnect and dns-prefetch hints
        $hints = [];

        foreach ($domains as $domain => $priority) {
            if (isset($existingHints[$domain])) {
                continue; // Already has a hint
            }

            $scheme = 'https';
            $origin = $scheme . '://' . $domain;

            if ($priority === 'preconnect' || in_array($domain, self::PRECONNECT_DOMAINS, true)) {
                // High-priority: full preconnect (DNS + TCP + TLS)
                $hints[] = sprintf(
                    '<link rel="preconnect" href="%s" crossorigin/>',
                    $origin
                );
            } else {
                // Lower priority: DNS prefetch only
                $hints[] = sprintf(
                    '<link rel="dns-prefetch" href="%s"/>',
                    $origin
                );
            }
        }

        if (empty($hints)) {
            return $html;
        }

        // Inject hints in <head>
        $hintHtml = "\n    <!-- TE CDN: DNS Prefetch & Preconnect Hints -->\n    "
            . implode("\n    ", $hints) . "\n";

        if (($headPos = stripos($html, '<head>')) !== false) {
            $pos = $headPos + 6;
            $html = substr($html, 0, $pos) . $hintHtml . substr($html, $pos);
        }

        $this->logger->debug('TransparentEdge: Injected DNS hints', [
            'count'   => count($hints),
            'domains' => array_keys($domains),
        ]);

        return $html;
    }

    /**
     * Extract unique external domains from HTML
     *
     * @param  string $html
     * @param  string $ownHost
     * @return array  domain => priority ('preconnect' or 'prefetch')
     */
    private function extractExternalDomains(string $html, string $ownHost): array
    {
        $domains = [];

        // Match all URLs in src, href, content attributes
        $patterns = [
            '/\b(?:src|href|action)\s*=\s*["\']https?:\/\/([^"\'\/\s]+)/i',
            '/url\(\s*["\']?https?:\/\/([^"\')\s]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $domain) {
                    // Clean up domain (remove path, port, etc.)
                    $domain = strtolower(preg_replace('/[:\/].*$/', '', $domain));

                    if (empty($domain) || $domain === $ownHost) {
                        continue;
                    }

                    // Check exclusions
                    if ($this->isExcluded($domain)) {
                        continue;
                    }

                    // Determine priority
                    if (!isset($domains[$domain])) {
                        $domains[$domain] = in_array($domain, self::PRECONNECT_DOMAINS, true)
                            ? 'preconnect'
                            : 'prefetch';
                    }
                }
            }
        }

        // Promote domains with scripts/stylesheets to preconnect
        if (preg_match_all('/<(?:script|link)[^>]+(?:src|href)="https?:\/\/([^"\/]+)/i', $html, $matches)) {
            foreach ($matches[1] as $domain) {
                $domain = strtolower($domain);
                if (isset($domains[$domain])) {
                    $domains[$domain] = 'preconnect';
                }
            }
        }

        // Limit to 8 domains max to avoid over-hinting
        return array_slice($domains, 0, 8, true);
    }

    /**
     * Check if a domain should be excluded
     *
     * @param  string $domain
     * @return bool
     */
    private function isExcluded(string $domain): bool
    {
        foreach (self::EXCLUDE_PATTERNS as $pattern) {
            if (preg_match($pattern, $domain)) {
                return true;
            }
        }

        // Exclude if it's a subdomain of the site's own domain
        $ownHost = parse_url($this->config->getBaseUrl(), PHP_URL_HOST) ?: '';
        $ownBase = preg_replace('/^www\./', '', $ownHost);
        if (!empty($ownBase) && (str_ends_with($domain, '.' . $ownBase) || $domain === $ownBase)) {
            return true;
        }

        return false;
    }

    /**
     * Get domains that already have prefetch/preconnect hints
     *
     * @param  string $html
     * @return array  domain => true
     */
    private function getExistingHints(string $html): array
    {
        $existing = [];

        if (preg_match_all('/<link[^>]+rel="(?:dns-prefetch|preconnect)"[^>]+href="(?:https?:)?\/\/([^"\/]+)"/i', $html, $matches)) {
            foreach ($matches[1] as $domain) {
                $existing[strtolower($domain)] = true;
            }
        }

        return $existing;
    }
}
