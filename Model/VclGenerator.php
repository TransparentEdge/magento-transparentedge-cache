<?php
/**
 * VCL generator for Transparent Edge CDN Dashboard
 *
 * Generates VCL snippets compatible with the Transparent Edge Dashboard.
 * Each snippet is a complete sub vcl_recv {} or sub vcl_backend_response {}
 * block ready to paste directly into the Dashboard VCL editor.
 *
 * TE VCL conventions (learned from production deployments):
 * - call bypass_cache; to skip caching (NOT return(pass))
 * - Multiple sub vcl_recv / sub vcl_backend_response blocks are additive
 * - vmods (urlplus, cookieplus, headerplus) are pre-loaded, no imports
 * - i3 activated via req.http.TCDN-i3-transform header
 * - urlplus.get_extension() for static file detection
 * - cookieplus.get/keep/write for cookie management in vcl_recv
 * - cookieplus.setcookie_delete/setcookie_write in vcl_backend_response
 * - beresp.ttl for Varnish TTL, beresp.http.Cache-Control for downstream
 * - beresp.uncacheable = true + beresp.ttl = 0s for no-cache
 * - # BEGIN [...] / # END [...] comment blocks for organization
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

class VclGenerator
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Generate the complete VCL for the TE Dashboard
     *
     * @return string
     */
    public function generateFull(): string
    {
        $host = $this->getHost();

        $vcl = [];
        $vcl[] = '# ═══════════════════════════════════════════════════════════';
        $vcl[] = '# Transparent Edge CDN — Magento 2 VCL Configuration';
        $vcl[] = '# Plugin version: 2.0.0';
        $vcl[] = '# Generated: ' . date('Y-m-d H:i:s');
        $vcl[] = '# Host: ' . $host;
        $vcl[] = '#';
        $vcl[] = '# Paste each block into the Transparent Edge Dashboard VCL editor.';
        $vcl[] = '# Multiple sub vcl_recv / sub vcl_backend_response blocks are additive.';
        $vcl[] = '# Dashboard: ' . $this->config->getDashboardUrl();
        $vcl[] = '# ═══════════════════════════════════════════════════════════';

        $vcl[] = '';
        $vcl[] = $this->generateVclRecv($host);
        $vcl[] = '';
        $vcl[] = $this->generateVclBackendResponse($host);

        return implode("\n", $vcl);
    }

    /**
     * Generate the i3 VCL snippet only (called by generate())
     *
     * @return string
     */
    public function generate(): string
    {
        if (!$this->config->isI3Enabled()) {
            return '# i3 image optimization is disabled';
        }

        return $this->generateFull();
    }

    /**
     * Generate admin bypass VCL (used by status CLI command)
     *
     * @return string
     */
    public function generateAdminBypass(): string
    {
        if (!$this->config->isAdminBypassEnabled()) {
            return '# Admin bypass is disabled';
        }

        return sprintf(
            '# Admin bypass' . "\n" .
            'if (req.http.Cookie ~ "%s=") {' . "\n" .
            '    call bypass_cache;' . "\n" .
            '}',
            Config::ADMIN_COOKIE_NAME
        );
    }

    // ──────────────────────────────────────────────────────────
    // vcl_recv
    // ──────────────────────────────────────────────────────────

    /**
     * Generate the vcl_recv block
     *
     * @param string $host
     * @return string
     */
    private function generateVclRecv(string $host): string
    {
        $l = [];

        $l[] = '# BEGIN [' . $host . ' — Magento 2 cache policy]';
        $l[] = 'sub vcl_recv {';
        $l[] = '    if (req.http.host ~ "' . $this->escapeHost($host) . '") {';

        // Non-GET/HEAD bypass
        $l[] = '        if (req.method !~ "^(GET|HEAD)$") {';
        $l[] = '            call bypass_cache;';
        $l[] = '        }';

        // Admin bypass
        if ($this->config->isAdminBypassEnabled()) {
            $l[] = '';
            $l[] = '        # Admin bypass — logged-in admins skip cache';
            $l[] = sprintf('        if (req.http.Cookie ~ "%s=") {', Config::ADMIN_COOKIE_NAME);
            $l[] = '            call bypass_cache;';
            $l[] = '        }';
        }

        // Magento session cookies — bypass for logged-in users and carts
        $l[] = '';
        $l[] = '        # Magento sessions — bypass for logged-in users and carts';
        $l[] = '        if (req.http.Cookie ~ "(PHPSESSID|adminhtml)") {';
        $l[] = '            if (urlplus.get_extension() ~ "^(gif|jpg|jpeg|png|svg|woff2?|ttf|ico|css|js)$") {';
        $l[] = '                # Static assets: strip cookies to allow caching';
        $l[] = '                unset req.http.Cookie;';
        $l[] = '            } else {';
        $l[] = '                call bypass_cache;';
        $l[] = '            }';
        $l[] = '        }';

        // Bypass non-cacheable Magento paths
        $l[] = '';
        $l[] = '        # Bypass non-cacheable Magento paths';
        $l[] = '        if (req.url ~ "^/(checkout|customer|wishlist|multishipping|paypal|catalogsearch)") {';
        $l[] = '            call bypass_cache;';
        $l[] = '        }';

        // Custom URL exclusions from admin config
        $excludeUrls = $this->config->getExcludeUrls();
        if (!empty($excludeUrls)) {
            $l[] = '';
            $l[] = '        # Custom URL exclusions (configured in admin)';
            foreach ($excludeUrls as $url) {
                $pattern = str_replace('*', '.*', preg_quote($url, '/'));
                $l[] = sprintf('        if (req.url ~ "%s") {', $pattern);
                $l[] = '            call bypass_cache;';
                $l[] = '        }';
            }
        }

        // X-Magento-Vary handling for cache variation
        $l[] = '';
        $l[] = '        # X-Magento-Vary — cache variation per store/currency/customer group';
        $l[] = '        if (cookieplus.get("X-Magento-Vary")) {';
        $l[] = '            set req.http.X-Vary-TCDN = "X-Magento-Vary=" + cookieplus.get("X-Magento-Vary");';
        $l[] = '        }';

        // Vary protocol
        $l[] = '';
        $l[] = '        set req.http.TCDN-Command = "vary-protocol, " + req.http.TCDN-Command;';

        // Collapse cookies and clean URL
        $l[] = '';
        $l[] = '        # Clean up request';
        $l[] = '        headerplus.init(req);';
        $l[] = '        headerplus.collapse(req.http.Cookie);';
        $l[] = '        urlplus.query_delete("gclid");';
        $l[] = '        urlplus.query_delete("fbclid");';
        $l[] = '        urlplus.query_delete("utm_source");';
        $l[] = '        urlplus.query_delete("utm_medium");';
        $l[] = '        urlplus.query_delete("utm_campaign");';
        $l[] = '        urlplus.query_delete("utm_content");';
        $l[] = '        urlplus.query_delete("utm_term");';

        // Strip cookies from static files
        $l[] = '';
        $l[] = '        # Strip cookies from static files';
        $l[] = '        if (urlplus.get_extension() ~ "^(bmp|bz2|css|eot|gif|ico|jpe?g|js|mp3|mp4|ogg|otf|png|svg|swf|tbz|t?gz|tiff|ttf|woff2?)$") {';
        $l[] = '            unset req.http.Cookie;';
        $l[] = '        }';

        // i3 image optimization
        if ($this->config->isI3Enabled()) {
            $l[] = '';
            $l[] = '        # i3 image optimization';

            // Build TCDN-i3-transform value with quality inline
            $quality = $this->config->getI3Quality();
            $maxWidth = $this->config->getI3MaxWidth();

            if ($this->config->isI3AutoWebp()) {
                // Quality embedded in header: auto_webp:85%
                $i3Transform = ($quality > 0 && $quality < 100)
                    ? sprintf('auto_webp:%d%%', $quality)
                    : 'auto_webp';
            } else {
                $i3Transform = 'auto_optimize';
            }

            $l[] = '        if (req.url ~ "^/media/catalog/(product|category)/" ||';
            $l[] = '            req.url ~ "^/media/wysiwyg/" ||';
            $l[] = '            req.url ~ "^/media/captcha/") {';
            $l[] = sprintf('            set req.http.TCDN-i3-transform = "%s";', $i3Transform);

            // Max width still needs query params
            if ($maxWidth > 0) {
                $l[] = '            urlplus.parse(req.url);';
                $l[] = '            urlplus.query_delete_regex("^i3_");';
                $l[] = sprintf('            urlplus.query_add("i3_max_width", "%d");', $maxWidth);
                $l[] = '            urlplus.write();';
            }

            $l[] = '        }';
        }

        // HTTPS redirect
        $l[] = '';
        $l[] = '        call redirect_https;';

        $l[] = '    }';
        $l[] = '}';
        $l[] = '# END [' . $host . ' — Magento 2 cache policy]';

        return implode("\n", $l);
    }

    // ──────────────────────────────────────────────────────────
    // vcl_backend_response
    // ──────────────────────────────────────────────────────────

    /**
     * Generate the vcl_backend_response block
     *
     * @param string $host
     * @return string
     */
    private function generateVclBackendResponse(string $host): string
    {
        $htmlTtl          = $this->config->getHtmlTtl();
        $htmlBrowserTtl   = $this->config->getHtmlBrowserTtl();
        $staticTtl        = $this->config->getStaticTtl();
        $staticBrowserTtl = $this->config->getStaticBrowserTtl();
        $swr              = $this->config->getStaleWhileRevalidate();
        $sie              = $this->config->getStaleIfError();

        $l = [];

        $l[] = '# BEGIN [' . $host . ' — Magento 2 backend response]';
        $l[] = 'sub vcl_backend_response {';
        $l[] = '    if (bereq.http.Host == "' . $host . '") {';

        // Non-cacheable paths — must be before the cacheable rules
        $l[] = '';
        $l[] = '        # Non-cacheable paths — no-cache';
        $l[] = '        if (bereq.url ~ "^/(checkout|customer|wishlist|catalogsearch|paypal)" ||';
        $l[] = '                bereq.url ~ "^/(newsletter|sendfriend|multishipping|review/product/post)") {';
        $l[] = '            if (beresp.http.Content-Type ~ "text/html") {';
        $l[] = '                set beresp.http.Cache-Control = "no-cache, no-store, must-revalidate";';
        $l[] = '                set beresp.ttl = 0s;';
        $l[] = '                set beresp.uncacheable = true;';
        $l[] = '            }';
        $l[] = '        }';

        // Only cache 2xx responses
        $l[] = '';
        $l[] = '        if (beresp.status < 300) {';

        // Static assets
        $l[] = '';
        $l[] = '            # Static assets — long TTL, strip Set-Cookie';
        $l[] = '            if (urlplus.get_extension() ~ "(?i)(css|eot|gif|ico|jpe?g|js|png|svg|ttf|woff2?)" ||';
        $l[] = '                    beresp.http.Content-Type ~ "image/" ||';
        $l[] = '                    beresp.http.Content-Type ~ "text/(css|plain)" ||';
        $l[] = '                    beresp.http.Content-Type ~ "(application|text)/(x-)?javascript" ||';
        $l[] = '                    beresp.http.Content-Type ~ "font/") {';
        $l[] = '                if (!beresp.http.Cache-Control) {';
        $l[] = sprintf(
            '                    set beresp.http.Cache-Control = "max-age=%d, s-maxage=%d";',
            $staticBrowserTtl,
            $staticTtl
        );
        $l[] = sprintf('                    set beresp.ttl = %ds;', $staticTtl);
        $l[] = '                }';
        $l[] = '                cookieplus.setcookie_delete_regex(".*");';
        $l[] = '                cookieplus.setcookie_write();';
        $l[] = '            }';

        // HTML pages
        $l[] = '';
        $l[] = '            # HTML pages — controlled TTL with stale directives';
        $l[] = '            if (urlplus.get_extension() ~ "(?i)(html?)" ||';
        $l[] = '                    beresp.http.Content-Type ~ "text/html") {';

        // Build Cache-Control with stale directives
        $ccParts = [
            sprintf('max-age=%d', $htmlBrowserTtl),
            sprintf('s-maxage=%d', $htmlTtl),
        ];
        if ($swr > 0) {
            $ccParts[] = sprintf('stale-while-revalidate=%d', $swr);
        }
        if ($sie > 0) {
            $ccParts[] = sprintf('stale-if-error=%d', $sie);
        }

        $l[] = sprintf(
            '                set beresp.http.Cache-Control = "%s";',
            implode(', ', $ccParts)
        );
        $l[] = sprintf('                set beresp.ttl = %ds;', $htmlTtl);
        $l[] = '            }';

        // i3 — Vary: Accept for content negotiation
        if ($this->config->isI3Enabled()) {
            $l[] = '';
            $l[] = '            # i3 images — Vary for content negotiation (WebP/AVIF)';
            $l[] = '            if (bereq.url ~ "^/media/catalog/(product|category)/" ||';
            $l[] = '                    bereq.url ~ "^/media/wysiwyg/") {';
            $l[] = '                set beresp.http.Vary = "Accept";';
            $l[] = '            }';
        }

        $l[] = '        }';  // end beresp.status < 300

        // 404s — short TTL to avoid caching broken URLs forever
        $l[] = '';
        $l[] = '        # 404s — short TTL';
        $l[] = '        if (beresp.status == 404) {';
        $l[] = '            set beresp.http.Cache-Control = "max-age=60";';
        $l[] = '            set beresp.ttl = 60s;';
        $l[] = '        }';

        $l[] = '    }';
        $l[] = '}';
        $l[] = '# END [' . $host . ' — Magento 2 backend response]';

        return implode("\n", $l);
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Extract hostname from the Magento base URL
     *
     * @return string
     */
    private function getHost(): string
    {
        $baseUrl = $this->config->getBaseUrl();
        return parse_url($baseUrl, PHP_URL_HOST) ?: 'your-magento-domain.com';
    }

    /**
     * Escape hostname dots for VCL regex
     *
     * @param string $host
     * @return string
     */
    private function escapeHost(string $host): string
    {
        return str_replace('.', '\\.', $host);
    }
}
