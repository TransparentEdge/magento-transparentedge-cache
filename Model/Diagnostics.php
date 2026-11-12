<?php
/**
 * Transparent Edge CDN integration for Magento 2
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Checks that the surrounding Magento is actually able to let the CDN work.
 *
 * The module can be configured perfectly, emit every header correctly and send
 * every invalidation to the API, and still cache nothing at all, because of
 * settings that live outside it. That failure is silent: nothing errors, the
 * panel looks healthy and the merchant concludes the CDN does not work.
 *
 * This class exists to make those failures loud. Each check returns a plain
 * array so the admin widget, the health endpoint and the CLI can all render the
 * same findings without duplicating the logic.
 */
class Diagnostics
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_OK       = 'ok';

    /**
     * Magento's own values for system/full_page_cache/caching_application.
     *
     * Mirrored here rather than importing Magento\PageCache\Model\Config so
     * that this class stays cheap to construct: it is read on every admin
     * dashboard render and on the public health endpoint.
     */
    public const FPC_BUILT_IN = 1;
    public const FPC_VARNISH  = 2;

    /**
     * Heartbeat the warm-up drain stamps on every run, including idle ones.
     *
     * Declared here, next to the check that reads it, rather than on Warmup
     * which writes it. Diagnostics is constructed on every admin dashboard
     * render and on the public health endpoint, so it must not pull Warmup
     * in — that would drag the category collection and store emulation along
     * with it.
     */
    public const WARMUP_HEARTBEAT_KEY = 'te_cdn_warmup_heartbeat';

    /**
     * A heartbeat older than this means the cron is not running. The drain
     * is scheduled every 5 minutes, so 15 tolerates a couple of misses.
     */
    public const WARMUP_HEARTBEAT_STALE_AFTER = 900;

    /**
     * What a cacheable response was actually carrying when it left the origin.
     *
     * Written by HeaderManager from real traffic — no synthetic request, no
     * HTTP call out and back, which on a site with Bot Management would be
     * answered with a 403 anyway.
     *
     * This exists because of how long the last one took to find. The plugin
     * was emitting correct public headers and the edge was caching nothing:
     * every response carried a Set-Cookie, and a response with Set-Cookie is
     * never cached. Everything looked right from Magento's side, and the only
     * way to see it was to read the headers on the wire. Now the origin
     * notices by itself.
     */
    public const CACHE_BLOCKERS_KEY = 'te_cdn_cache_blockers';

    /**
     * A reading older than this says nothing about the site as it is now.
     */
    public const CACHE_BLOCKERS_STALE_AFTER = 86400;

    private const PATH_CACHING_APPLICATION = 'system/full_page_cache/caching_application';
    private const PATH_FPC_TTL             = 'system/full_page_cache/ttl';

    /** Magento's cache type id for the full page cache. */
    private const CACHE_TYPE_FPC = 'full_page';

    private ScopeConfigInterface $scopeConfig;
    private StateInterface $cacheState;
    private Config $config;
    private CacheInterface $cache;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StateInterface $cacheState,
        Config $config,
        CacheInterface $cache
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->cacheState  = $cacheState;
        $this->config      = $config;
        $this->cache       = $cache;
    }

    /**
     * Run every check.
     *
     * @return array<int, array{id:string, severity:string, title:string, detail:string, fix:string}>
     */
    public function run(): array
    {
        $checks = [
            $this->checkFullPageCacheEnabled(),
            $this->checkCachingApplication(),
            $this->checkWarmupCron(),
            $this->checkCacheBlockers(),
        ];

        return array_values(array_filter($checks));
    }

    /**
     * Only the findings that need the merchant to do something.
     *
     * @return array<int, array{id:string, severity:string, title:string, detail:string, fix:string}>
     */
    public function getProblems(): array
    {
        return array_values(array_filter(
            $this->run(),
            static fn(array $c): bool => $c['severity'] !== self::SEVERITY_OK
        ));
    }

    /**
     * @return bool True when at least one check is critical.
     */
    public function hasCriticalProblem(): bool
    {
        foreach ($this->run() as $check) {
            if ($check['severity'] === self::SEVERITY_CRITICAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one that matters most.
     *
     * With caching_application set to Built-in, Magento\Framework\App\PageCache\
     * Kernel::process() runs on every cacheable response: it recognises the
     * "public ... s-maxage" header the layout just set, stores the page in
     * Magento's own cache, and then calls setNoCacheHeaders() and strips
     * X-Magento-Tags before the response leaves the origin.
     *
     * That is correct behaviour for Built-in — Magento's internal cache IS the
     * cache, and it does not want a proxy in front holding copies. But with a
     * CDN in front it means nothing is ever cacheable at the edge, and nothing
     * anywhere says so. Built-in is Magento's default, so this is the state a
     * fresh install lands in.
     *
     * @return array{id:string, severity:string, title:string, detail:string, fix:string}
     */
    private function checkCachingApplication(): array
    {
        $value = (int) $this->scopeConfig->getValue(self::PATH_CACHING_APPLICATION);

        if ($value === self::FPC_VARNISH) {
            return [
                'id'       => 'caching_application',
                'severity' => self::SEVERITY_OK,
                'title'    => (string) __('Full Page Cache application: Varnish'),
                'detail'   => (string) __('Magento emits public cache headers and tags, so the CDN can cache.'),
                'fix'      => '',
            ];
        }

        return [
            'id'       => 'caching_application',
            'severity' => self::SEVERITY_CRITICAL,
            'title'    => (string) __('The CDN cannot cache anything: Full Page Cache is set to Built-in'),
            'detail'   => (string) __(
                'With the Built-in application, Magento stores pages in its own cache and then replaces the '
                . 'public cache headers with no-store and removes X-Magento-Tags before the response leaves '
                . 'the origin. Every page therefore reaches the CDN marked as non-cacheable, and surgical '
                . 'invalidation degrades to a full purge. Built-in is Magento\'s default value, so this is '
                . 'not something you did wrong. Set the application to Varnish, which is the correct setting '
                . 'whenever any CDN or reverse proxy sits in front of Magento.'
            ),
            'fix'      => 'bin/magento config:set ' . self::PATH_CACHING_APPLICATION . ' ' . self::FPC_VARNISH
                . ' && bin/magento cache:flush',
        ];
    }

    /**
     * A disabled full page cache type stops the layout from ever being marked
     * cacheable, so no tags and no public headers are produced either.
     *
     * @return array{id:string, severity:string, title:string, detail:string, fix:string}
     */
    private function checkFullPageCacheEnabled(): array
    {
        if ($this->cacheState->isEnabled(self::CACHE_TYPE_FPC)) {
            $ttl = (int) $this->scopeConfig->getValue(self::PATH_FPC_TTL);

            return [
                'id'       => 'full_page_cache_type',
                'severity' => self::SEVERITY_OK,
                'title'    => (string) __('Full Page Cache type: enabled'),
                'detail'   => (string) __('Magento TTL: %1 seconds.', $ttl > 0 ? $ttl : 86400),
                'fix'      => '',
            ];
        }

        return [
            'id'       => 'full_page_cache_type',
            'severity' => self::SEVERITY_CRITICAL,
            'title'    => (string) __('The CDN cannot cache anything: the Full Page Cache type is disabled'),
            'detail'   => (string) __(
                'With the full_page cache type disabled, Magento never marks a layout as cacheable, so it '
                . 'emits neither public cache headers nor X-Magento-Tags. Without tags this module cannot '
                . 'build surgical Surrogate-Keys and every page falls back to the global key.'
            ),
            'fix'      => 'bin/magento cache:enable full_page && bin/magento cache:flush',
        ];
    }

    /**
     * Is the warm-up cron alive?
     *
     * Warming used to happen inside the request that triggered it, which was
     * its own problem: saving one product queued the whole category tree and
     * processed it before the save returned. It now leaves URLs in a queue
     * that Cron/DeferredWarmup drains every five minutes.
     *
     * That trade is worth making, but it buys a silent failure: with
     * Magento's cron stopped — a dead crontab, a cron_schedule table wedged
     * on stuck jobs, a consumer that never came back after a deploy —
     * nothing warms, nothing errors, and the merchant has no way to tell.
     * The drain stamps a heartbeat on every run, including the runs that
     * find nothing to do, so its absence means the cron and not the queue.
     *
     * @return array|null Null when warming is off and the check is moot.
     */
    private function checkWarmupCron(): ?array
    {
        if (!$this->config->isWarmupEnabled()) {
            return null;
        }

        $beat    = $this->loadWarmupHeartbeat();
        $lastRun = (int) ($beat['at'] ?? 0);
        $fix     = 'bin/magento cron:run --group=default   # and check your crontab: '
            . 'bin/magento cron:install';

        if ($lastRun === 0) {
            return [
                'id'       => 'warmup_cron',
                'severity' => self::SEVERITY_WARNING,
                'title'    => (string) __('Warm-up has never run'),
                'detail'   => (string) __(
                    'Warming is handed to a cron job that runs every five minutes, and it has not run '
                    . 'once yet. On a site installed minutes ago that is expected. Otherwise Magento '
                    . 'cron is not running and nothing is being warmed — silently, because a warm-up '
                    . 'that never happens produces no error.'
                ),
                'fix'      => $fix,
            ];
        }

        $age = time() - $lastRun;

        if ($age > self::WARMUP_HEARTBEAT_STALE_AFTER) {
            return [
                'id'       => 'warmup_cron',
                'severity' => self::SEVERITY_WARNING,
                'title'    => (string) __('Warm-up stopped running %1 minutes ago', (int) floor($age / 60)),
                'detail'   => (string) __(
                    'The warm-up drain is scheduled every five minutes and last ran %1 minutes ago, so '
                    . 'Magento cron has most likely stopped. Pages still cache and the edge still '
                    . 'revalidates them on demand, but nothing is warmed ahead of the first visitor.',
                    (int) floor($age / 60)
                ),
                'fix'      => $fix,
            ];
        }

        return [
            'id'       => 'warmup_cron',
            'severity' => self::SEVERITY_OK,
            'title'    => (string) __('Warm-up cron: running'),
            'detail'   => (string) __(
                'Last drain %1 minutes ago: %2 URL(s) queued by content edits, %3 warmed.',
                (int) floor($age / 60),
                (int) ($beat['pending'] ?? 0),
                (int) ($beat['warmed'] ?? 0)
            ),
            'fix'      => '',
        ];
    }

    /**
     * Is anything on a cacheable response stopping the edge from caching it?
     *
     * Two things do it silently, and both look fine from inside Magento:
     *
     *   Set-Cookie — a response that carries one is never cached. Magento
     *   hands a PHPSESSID to anonymous visitors too, so without the VCL
     *   stripping it on cacheable HTML the whole site goes uncached.
     *
     *   Vary on Cookie or User-Agent — the object is cached, but keyed on a
     *   value that is different for almost every visitor, so it is never
     *   served twice. Magento emits a safe Vary by itself; a third-party
     *   module appending to it is the way this shows up.
     *
     * @return array|null Null when there is no reading to report on.
     */
    private function checkCacheBlockers(): ?array
    {
        $r = $this->loadCacheBlockers();
        if ($r === [] || (time() - (int) ($r['at'] ?? 0)) > self::CACHE_BLOCKERS_STALE_AFTER) {
            return null;
        }

        $cookies = array_filter(array_map('strval', (array) ($r['cookies'] ?? [])));
        $vary    = (string) ($r['vary'] ?? '');
        $varyMal = array_values(array_filter(
            array_map('trim', explode(',', $vary)),
            static function ($t) {
                return in_array(strtolower($t), ['cookie', 'user-agent'], true);
            }
        ));

        if ($cookies !== []) {
            return [
                'id'       => 'cache_blockers',
                'severity' => self::SEVERITY_CRITICAL,
                'title'    => (string) __('Cacheable pages are sending cookies, so nothing is cached'),
                'detail'   => (string) __(
                    'A page that should be cached left this server with a Set-Cookie for %1. The CDN '
                    . 'never stores a response that sets a cookie, so every visitor is served from here '
                    . 'instead of from the edge — silently, because the page itself is correct. The VCL '
                    . 'the plugin generates strips these on cacheable responses; this usually means the '
                    . 'deployed VCL predates that, or was edited.',
                    implode(', ', $cookies)
                ),
                'fix'      => 'bin/magento transparentedge:status --vcl   # regenerate and redeploy the VCL',
            ];
        }

        if ($varyMal !== []) {
            return [
                'id'       => 'cache_blockers',
                'severity' => self::SEVERITY_WARNING,
                'title'    => (string) __('Cacheable pages vary on %1', implode(', ', $varyMal)),
                'detail'   => (string) __(
                    'The response carries Vary: %1. The edge stores the page, but keyed on a value that '
                    . 'differs for nearly every visitor, so a stored copy is almost never reused. Magento '
                    . 'sends a safe Vary on its own, so something else is adding to it — usually a '
                    . 'third-party module.',
                    $vary
                ),
                'fix'      => '',
            ];
        }

        return [
            'id'       => 'cache_blockers',
            'severity' => self::SEVERITY_OK,
            'title'    => (string) __('Cacheable pages leave clean'),
            'detail'   => (string) __(
                'Last cacheable page sent from here carried no Set-Cookie and varied only on %1.',
                $vary !== '' ? $vary : (string) __('nothing')
            ),
            'fix'      => '',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadCacheBlockers(): array
    {
        $raw = $this->cache->load(self::CACHE_BLOCKERS_KEY);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadWarmupHeartbeat(): array
    {
        $raw = $this->cache->load(self::WARMUP_HEARTBEAT_KEY);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return bool Whether the checks are worth showing at all.
     */
    public function isRelevant(): bool
    {
        return $this->config->isEnabled();
    }
}
