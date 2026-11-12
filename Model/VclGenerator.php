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
        $vcl[] = '# Plugin version: ' . Config::VERSION;
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

        // vcl_deliver is always emitted: it carries the header hygiene, and
        // the Speculation-Rules injection only when that is switched on.
        $vcl[] = '';
        $vcl[] = $this->generateVclDeliver($host);

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

        // No bypass for non-GET/HEAD: the platform only ever caches GET and
        // HEAD, so writing the rule adds a line that can never fire.

        // Admin bypass
        if ($this->config->isAdminBypassEnabled()) {
            $l[] = '';
            $l[] = '        # Admin bypass — logged-in admins skip cache';
            $l[] = sprintf('        if (req.http.Cookie ~ "%s=") {', Config::ADMIN_COOKIE_NAME);
            $l[] = '            call bypass_cache;';
            $l[] = '        }';
        }

        // No bypass on PHPSESSID.
        //
        // Magento hands a PHPSESSID to anonymous visitors too, so bypassing
        // on it meant nothing was cached from a visitor's second page view
        // onward. It also does not do what it looks like it does: a GET
        // carrying a cookie is cached normally by the platform, so this rule
        // was never the thing keeping personalized pages out of the cache.
        // What keeps them out is the origin — Magento marks them
        // non-cacheable and answers with Set-Cookie, and the platform does
        // not cache a response with Set-Cookie.
        //
        // Variation for logged-in visitors is handled by X-Magento-Vary
        // below, which is what Magento itself uses.

        // Personalized routes.
        //
        // Deliberately kept even though the origin already marks these
        // non-cacheable: serving one visitor's cart or address book to
        // another is the one failure here that cannot be walked back, and
        // the pages are not cacheable anyway, so the rule costs no offload.
        // catalogsearch is NOT in the list — search result pages are
        // cacheable and heavily requested, and bypassing them was giving
        // away offload for nothing.
        $l[] = '';
        $l[] = '        # Personalized routes — never cacheable';
        $l[] = '        if (req.url ~ "^/(checkout|customer|wishlist|multishipping)") {';
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
        // No vary-protocol. The block ends in redirect_https, so every object
        // that reaches the cache was fetched over https and the http variant
        // can never be populated. Asking the edge to key on protocol buys a
        // second bucket that nothing will ever fill.

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

        // The per-URL no-store block that used to sit here is gone. It did
        // not do what it promised: it set no-store, and then the HTML block
        // below — whose guard only looked for "s-maxage" — found no s-maxage
        // in "no-cache, no-store, must-revalidate" and overwrote it with a
        // public TTL. A 200 on /checkout left the origin marked no-store and
        // reached the browser with max-age=3600.
        //
        // Nothing replaces it: Magento already answers those routes
        // non-cacheable, and vcl_recv passes them.

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
        $l[] = '            # HTML pages — the origin decides';
        $l[] = '            if (urlplus.get_extension() ~ "(?i)(html?)" ||';
        $l[] = '                    beresp.http.Content-Type ~ "text/html") {';
        $l[] = '                # Only when the origin said nothing at all. The plugin already';
        $l[] = '                # sends a per-page-type Cache-Control, and a page it marked';
        $l[] = '                # no-store must stay no-store.';
        $l[] = '                if (!beresp.http.Cache-Control) {';

        // Build Cache-Control with stale directives (only as fallback)
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
            '                    set beresp.http.Cache-Control = "%s";',
            implode(', ', $ccParts)
        );
        $l[] = sprintf('                    set beresp.ttl = %ds;', $htmlTtl);
        $l[] = '                }';
        $l[] = '';
        $l[] = '                # Magento sets a PHPSESSID on every HTML response, anonymous';
        $l[] = '                # ones included, and the platform never caches a response that';
        $l[] = '                # carries Set-Cookie. Without this line nothing is cached at';
        $l[] = '                # all: measured on a live site, every request answered';
        $l[] = '                # tp-cache=pass with tcdn-hits=0.';
        $l[] = '                #';
        $l[] = '                # Guarded on uncacheable, and that guard is not optional: a';
        $l[] = '                # passed response (cart, checkout, a logged-in customer)';
        $l[] = '                # carries the session cookie the visitor needs. Strip it there';
        $l[] = '                # and the browser never gets a session, so the cart stops';
        $l[] = '                # persisting between pages.';
        $l[] = '                if (!beresp.uncacheable) {';
        $l[] = '                    cookieplus.setcookie_delete_regex(".*");';
        $l[] = '                    cookieplus.setcookie_write();';
        $l[] = '                }';
        $l[] = '            }';

        // i3 — Vary: Accept for content negotiation
        if ($this->config->isI3Enabled()) {
            $l[] = '';
            // No Vary: Accept on i3 images.
            //
            // The WebP/AVIF negotiation happens inside the edge, which keys
            // each transform as its own cache object. Adding Vary: Accept
            // does not enable that negotiation — it is already on — it just
            // splits every image by the visitor's full Accept string, and
            // those strings differ between browsers and between versions of
            // the same browser. The header also reaches the browser, so the
            // same split happens again in each visitor's own cache.
        }

        $l[] = '        }';  // end beresp.status < 300

        // 404s — a short POSITIVE TTL, and only if the origin kept quiet.
        // Absorbs a crawler hammering dead URLs without pinning a 404 over a
        // page the origin has since brought back.
        $l[] = '';
        $l[] = '        # 404s — short TTL, unless the origin set its own policy';
        $l[] = '        if (beresp.status == 404 && !beresp.http.Cache-Control) {';
        $l[] = '            set beresp.http.Cache-Control = "max-age=60";';
        $l[] = '            set beresp.ttl = 60s;';
        $l[] = '        }';

        // Nothing here for other errors or for redirects, on purpose.
        //
        // The branch that used to force no-store and ttl 0 on every status
        // >= 400 looked like hardening and was the opposite: it takes away
        // grace and stale-if-error exactly during an origin outage, so every
        // visitor is sent to an origin that is already failing. The platform
        // does not cache errors by default, so the branch bought nothing and
        // cost resilience.
        //
        // Redirects are left alone for the same reason: the origin's
        // Cache-Control decides, and where it says nothing the platform's
        // own default applies.

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
    /**
     * Generate VCL deliver block for Speculation Rules header injection.
     *
     * Injects the Speculation-Rules header at the edge, avoiding the need
     * for the origin to add it. Only applied to HTML responses, excluding
     * admin, checkout, and API paths.
     *
     * @param  string $host
     * @return string
     */
    private function generateVclDeliver(string $host): string
    {
        $rulesPath = '/transparentedge/speculationRules/index';

        $l = [];
        $l[] = '# BEGIN [' . $host . ' — Magento 2 deliver]';
        $l[] = 'sub vcl_deliver {';
        $l[] = '    if (req.http.host ~ "' . $this->escapeHost($host) . '") {';
        $l[] = '';
        $l[] = '        # Internal headers must not reach the public. Surrogate-Keys is the';
        $l[] = '        # invalidation map of the whole catalogue: published, it tells anyone';
        $l[] = '        # which keys to aim at. X-Magento-Tags leaks the same thing and names';
        $l[] = '        # the stack behind it. Stripping them here is safe for invalidation:';
        $l[] = '        # vcl_deliver runs after the object is stored with its keys.';
        $l[] = '        unset resp.http.Surrogate-Keys;';
        $l[] = '        unset resp.http.X-Magento-Tags;';
        $l[] = '';
        $seguridad = $this->config->getSecurityHeaders();
        if ($seguridad !== []) {
            $l[] = '';
            $l[] = '        # Security headers. They live here rather than in PHP so they also';
            $l[] = '        # cover static files, images and error pages, none of which reach';
            $l[] = '        # Magento. Switch each one on under Stores > Configuration >';
            $l[] = '        # Transparent Edge CDN > Security.';
            $l[] = '        #';
            $l[] = '        # If your VCL already sets any of these by hand, remove the hand';
            $l[] = '        # written copy: setting the same header twice is harmless but the';
            $l[] = '        # two will drift apart.';
            foreach ($seguridad as $nombre => $valor) {
                $l[] = sprintf('        set resp.http.%s = "%s";', $nombre, str_replace('"', '\\"', $valor));
            }
        }

        $l[] = '';
        $l[] = '        # X-TE-Debug and X-TE-Page-Type are deliberately NOT stripped. They';
        $l[] = '        # only exist while debug mode is on, and the whole point of debug';
        $l[] = '        # mode is to read them from outside — at the edge, which is where';
        $l[] = '        # cache behaviour actually happens. Turn debug off in production and';
        $l[] = '        # they stop being sent at all.';

        if (!$this->config->isSpeculationEnabled()
            || $this->config->getSpeculationInjection() !== 'vcl') {
            $l[] = '    }';
            $l[] = '}';
            $l[] = '# END [' . $host . ' — Magento 2 deliver]';

            return implode("\n", $l);
        }

        $l[] = '';
        $l[] = '        # Speculation Rules — injected at the edge';
        $l[] = '        if (resp.http.Content-Type ~ "text/html"';
        $l[] = '            && req.url !~ "^/admin"';
        $l[] = '            && req.url !~ "^/checkout"';
        $l[] = '            && req.url !~ "^/customer"';
        $l[] = '            && req.url !~ "^/rest/"';
        $l[] = '            && req.url !~ "^/graphql") {';
        $l[] = sprintf('            set resp.http.Speculation-Rules = {""%s%s""};',
            'https://' . $host, $rulesPath);
        $l[] = '        }';
        $l[] = '    }';
        $l[] = '}';
        $l[] = '# END [' . $host . ' — Magento 2 deliver]';

        return implode("\n", $l);
    }

    private function escapeHost(string $host): string
    {
        return str_replace('.', '\\.', $host);
    }
}
