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
     * Checked nothing, because there was nothing to check yet.
     *
     * This exists because of how the original problem hid. Some checks read
     * a reading taken from real traffic, and when that reading is missing
     * the honest answer is "I don't know", not silence — silence is what a
     * passing check looks like. The plugin said nothing for weeks and we
     * read it as everything being fine.
     */
    public const SEVERITY_UNKNOWN  = 'unknown';

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

    /**
     * Something else on the page is already telling the browser what to
     * prefetch. Written by the speculation optimizer when it steps aside.
     */
    public const SPECULATION_CONFLICT_KEY = 'te_cdn_speculation_conflict';

    /**
     * Cookies Magento sets on ordinary pages by itself.
     *
     * Seeing one of these on a cacheable response is the normal state of a
     * working Magento, not a finding. Anything else on the list is somebody
     * else's, and worth saying out loud.
     */
    private const EXPECTED_COOKIES = ['PHPSESSID', 'FORM_KEY', 'MAGE-CACHE-SESSID', 'MAGE-MESSAGES',
                                      'PRIVATE_CONTENT_VERSION', 'SECTION_DATA_IDS', 'X-MAGENTO-VARY'];

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
            $this->checkSpeculationConflict(),
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
        // "Not measured yet" is not a problem: it would mean the admin
        // dashboard raises a flag after every cache flush, every time.
        return array_values(array_filter(
            $this->run(),
            static fn(array $c): bool => !in_array($c['severity'], [self::SEVERITY_OK, self::SEVERITY_UNKNOWN], true)
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
                'title'    => (string) __('No sign of the warm-up cron yet'),
                'detail'   => (string) __(
                    'Warming is handed to a cron job that runs every five minutes, and there is no '
                    . 'record of it running. Two ordinary reasons come before worrying: the site was '
                    . 'installed minutes ago, or the cache was flushed — this record lives in the '
                    . 'Magento cache, so bin/magento cache:flush and a deployment both clear it, and '
                    . 'the next cron run puts it back. If it is still saying this more than five '
                    . 'minutes after a flush, Magento cron is not running and nothing is being warmed '
                    . '— silently, because a warm-up that never happens produces no error.'
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
            return [
                'id'       => 'cache_blockers',
                'severity' => self::SEVERITY_UNKNOWN,
                'title'    => (string) __('Not checked yet: what cacheable pages are sending'),
                'detail'   => (string) __(
                    'This reading is taken while Magento renders a cacheable page, and there has not '
                    . 'been one since the cache was last flushed. Request any page that is not already '
                    . 'cached at the edge and it will fill in.'
                ),
                'fix'      => '',
            ];
        }

        $cookies = array_filter(array_map('strval', (array) ($r['cookies'] ?? [])));
        $vary    = (string) ($r['vary'] ?? '');
        $varyMal = array_values(array_filter(
            array_map('trim', explode(',', $vary)),
            static function ($t) {
                return in_array(strtolower($t), ['cookie', 'user-agent'], true);
            }
        ));

        // Magento gives every visitor a session, anonymous ones included, so
        // a cacheable page leaving with PHPSESSID is normal and says nothing
        // about whether caching works. What decides that is whether the
        // DEPLOYED VCL strips it, and the origin cannot see the deployed VCL.
        //
        // This was a critical at first, which was wrong: it would have fired
        // on every correctly configured Magento there is, and a critical that
        // is always on teaches people to ignore criticals.
        $esperadas = array_filter($cookies, static function ($c) {
            return in_array(strtoupper($c), self::EXPECTED_COOKIES, true);
        });
        $extranas = array_values(array_diff($cookies, $esperadas));

        if ($extranas !== []) {
            return [
                'id'       => 'cache_blockers',
                'severity' => self::SEVERITY_WARNING,
                'title'    => (string) __('Cacheable pages are setting cookies that will not reach visitors: %1', implode(', ', $extranas)),
                'detail'   => (string) __(
                    'A cacheable page left this server setting %1. The VCL strips every Set-Cookie from '
                    . 'responses it caches — it has to, because the CDN will not store a response that '
                    . 'sets a cookie — so whatever puts these there will not work for anyone served from '
                    . 'the edge, which is most visitors. Either the feature behind them belongs on a '
                    . 'page that is not cached, or it needs to set them from JavaScript instead.',
                    implode(', ', $extranas)
                ),
                'fix'      => '',
            ];
        }

        if ($esperadas !== []) {
            return [
                'id'       => 'cache_blockers',
                'severity' => self::SEVERITY_WARNING,
                'title'    => (string) __('Check that your deployed VCL strips Set-Cookie'),
                'detail'   => (string) __(
                    'Cacheable pages leave this server with %1, which is normal: Magento gives a session '
                    . 'to anonymous visitors too. The CDN does not store a response that sets a cookie, '
                    . 'so the VCL has to strip it — the one this plugin generates does, on cacheable '
                    . 'responses only. From here there is no way to see which VCL is actually deployed, '
                    . 'so if caching looks wrong, regenerate it and compare with what is in the panel. '
                    . 'A VCL generated before v2.1 does not strip it, and nothing will be cached.',
                    implode(', ', $esperadas)
                ),
                'fix'      => 'bin/magento transparentedge:status --vcl',
            ];
        }

        if ($varyMal !== []) {
            return [
                'id'       => 'cache_blockers',
                'severity' => self::SEVERITY_WARNING,
                'title'    => (string) __('Something is adding Vary: %1 to cacheable pages', implode(', ', $varyMal)),
                'detail'   => (string) __(
                    'A cacheable page was built with Vary: %1. Varying on that keys the cached object '
                    . 'on a value that differs for nearly every visitor, so a stored copy is almost '
                    . 'never served twice. The plugin strips it before the response leaves, so caching '
                    . 'is not broken — but Magento does not do this by itself, so a module on this '
                    . 'installation is doing it, and it may be doing the same on routes the plugin '
                    . 'does not handle. Worth finding.',
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
     * Is something else on the page already prefetching?
     *
     * The browser merges every speculation rule set it finds, so a second
     * one does not replace ours — both lists get fetched. The plugin steps
     * aside when it sees one, which keeps the page honest but means the
     * exclusions we would have applied are not being applied by anyone.
     *
     * @return array|null Null when nothing was found.
     */
    private function checkSpeculationConflict(): ?array
    {
        if (!$this->config->isSpeculationEnabled()) {
            return null;
        }

        $r = $this->loadJson(self::SPECULATION_CONFLICT_KEY);
        $viejo = (time() - (int) ($r['at'] ?? 0)) > self::CACHE_BLOCKERS_STALE_AFTER;

        if ($r === [] || $viejo) {
            return [
                'id'       => 'speculation_conflict',
                'severity' => self::SEVERITY_UNKNOWN,
                'title'    => (string) __('Not checked yet: whether anything else is prefetching'),
                'detail'   => (string) __(
                    'Checked while a page is rendered, and none has been since the cache was last '
                    . 'flushed.'
                ),
                'fix'      => '',
            ];
        }

        $que = (string) ($r['what'] ?? '');
        if ($que === '') {
            return [
                'id'       => 'speculation_conflict',
                'severity' => self::SEVERITY_OK,
                'title'    => (string) __('Speculation rules: nothing else is prefetching'),
                'detail'   => (string) __('The plugin is the only thing telling the browser what to prefetch.'),
                'fix'      => '',
            ];
        }

        return [
            'id'       => 'speculation_conflict',
            'severity' => self::SEVERITY_WARNING,
            'title'    => (string) __('Something else is prefetching: %1', $que),
            'detail'   => (string) __(
                'A page was rendered with %1 already on it, so the plugin did not add its own '
                . 'speculation rules. Browsers merge every rule set on a page rather than picking one, '
                . 'so adding ours would have made visitors fetch both lists. Leaving it alone is the '
                . 'safe thing to do, but the other one does not know which URLs must never be '
                . 'prefetched — cart, checkout, customer pages — so those may be getting fetched in '
                . 'the background. Pick one: switch the other one off to use the plugin, or switch '
                . 'speculation off here.',
                $que
            ),
            'fix'      => 'bin/magento config:set transparentedge/speculation/enabled 0',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $clave): array
    {
        $raw = $this->cache->load($clave);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
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
