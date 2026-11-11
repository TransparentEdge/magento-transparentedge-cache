<?php
/**
 * Cache warm-up engine for Transparent Edge CDN
 *
 * Warming is delegated to the platform: a soft invalidation with refetch on
 * POST /v1/companies/{id}/invalidate/. Every edge node then re-fetches the
 * object by itself and the refetchers collapse those fetches.
 *
 * Read that last sentence narrowly. The collapsing is per object across
 * nodes: for ONE URL the origin sees one or two renders instead of one per
 * PoP. It does not collapse across different URLs. Warming N URLs still
 * costs the origin on the order of N renders, so the only real defence
 * against amplification is warming fewer URLs. That is what the deferred
 * queue and the hourly budget below are for.
 *
 * The previous implementation issued an HTTP GET per URL from Magento. That
 * was wrong in three separate ways, all of them measured:
 *
 *  - It loaded the origin once per URL. A 200 URL batch meant 200 full page
 *    renders.
 *  - It warmed only the single edge node that answered the request, leaving
 *    every other PoP cold. Worst of both worlds.
 *  - It went out through the public URL, so on any site with Bot Management
 *    enabled the platform answered 403 and the warm-up failed in silence.
 *    We reproduced exactly that on our own test site.
 *
 * It also claimed to be non-blocking while ending in a blocking wait() on a
 * Guzzle pool, inside a shutdown handler, which pinned a PHP-FPM worker for
 * as long as the slowest page took — up to 200 URLs at a 30s timeout.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Framework\App\Area;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;
use TransparentEdge\CDN\Api\ApiClient;

class Warmup
{
    /**
     * Maximum URLs collected in one warm-up run.
     */
    private const MAX_URLS_PER_BATCH = 200;

    /**
     * URLs per API call. The platform takes the whole list in one request;
     * batching only keeps the payload and the log lines a sane size.
     */
    private const URLS_PER_API_CALL = 100;

    /**
     * Query parameters that decorate a URL without changing the page.
     *
     * Warming "/" and "/?___store=default" as two objects wastes half the
     * budget and leaves one of them cold anyway.
     */
    private const DECORATIVE_PARAMS = ['___store', '___from_store', 'SID', '___SID'];

    /**
     * Cache key holding the deferred queue shared by every request.
     */
    private const DEFERRED_CACHE_KEY = 'te_cdn_warmup_deferred';

    /**
     * Cache key prefix for the per-clock-hour spend counter.
     */
    private const BUDGET_CACHE_PREFIX = 'te_cdn_warmup_budget_';

    /**
     * Proof that the drain cron ran, and what it found.
     *
     * Warming moved out of the request and into cron, which bought us the
     * deduplication but introduced a way to fail in silence: with Magento's
     * cron stopped nothing warms, nothing errors and nothing says so. This
     * heartbeat is what Diagnostics reads to catch exactly that; the key
     * lives there, next to the check, so Diagnostics need not construct a
     * Warmup on every admin dashboard render.
     */
    private const HEARTBEAT_TTL = 86400;

    /**
     * Lock held while a drain is in flight.
     *
     * Moving the warm-up out of the request and into a cron every five
     * minutes fixed the amplification, but opened a new way to cause one: if
     * a drain outlives its cycle — a slow API, a big queue, a backed-up cron
     * — the next tick starts a second drain, both read the same queue and
     * both send it. The lock makes a drain wait its turn instead.
     *
     * The TTL is the safety net: if the process dies mid-drain without
     * releasing, warming resumes by itself one cycle later.
     */
    private const DRAIN_LOCK_KEY = 'te_cdn_warmup_drain_lock';

    private const DRAIN_LOCK_TTL = 600;

    /**
     * Hard ceiling on the deferred queue, so a bulk import cannot grow it
     * without bound between two drains.
     */
    private const MAX_DEFERRED_URLS = 2000;

    /**
     * How long a deferred URL stays worth warming. Past this it has either
     * been requested by somebody (and is warm) or nobody wants it.
     */
    private const DEFERRED_TTL = 3600;

    /**
     * Cache tag for everything this class stores.
     */
    private const CACHE_TAG = 'TE_CDN_WARMUP';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ClientFactory
     */
    private ClientFactory $clientFactory;

    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Emulation
     */
    private Emulation $appEmulation;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Queue of URLs to warm up
     *
     * @var array
     */
    private array $queue = [];

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * Host allowlist, resolved once per request.
     *
     * @var array<string>|null
     */
    private ?array $ownHosts = null;

    /**
     * @param Config                    $config
     * @param ClientFactory             $clientFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param StoreManagerInterface     $storeManager
     * @param Emulation                 $appEmulation
     * @param LoggerInterface           $logger
     * @param ApiClient                 $apiClient
     * @param CacheInterface            $cache
     */
    public function __construct(
        Config                    $config,
        ClientFactory             $clientFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        StoreManagerInterface     $storeManager,
        Emulation                 $appEmulation,
        LoggerInterface           $logger,
        ApiClient                 $apiClient,
        CacheInterface            $cache
    ) {
        $this->config                    = $config;
        $this->clientFactory             = $clientFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->storeManager              = $storeManager;
        $this->appEmulation              = $appEmulation;
        $this->logger                    = $logger;
        $this->apiClient                 = $apiClient;
        $this->cache                     = $cache;
    }

    // ──────────────────────────────────────────────
    // Deferred queue
    //
    // Nothing triggered by a content edit warms anything inline. Saving a
    // product used to queue the homepage plus every active category and
    // process the lot before the save returned. Three editors at twenty
    // saves an hour against a 200 category catalogue is 12.000 re-renders
    // an hour, charged to the origin, and paid for inside the admin
    // request. Edits now only leave URLs here; the cron drains them.
    //
    // Deferring buys the deduplication for free: sixty saves inside one
    // drain window collapse into one set of distinct URLs, warmed once.
    // ──────────────────────────────────────────────

    /**
     * Leave URLs for the next drain instead of warming them now.
     *
     * @param  array $urls
     * @return int   Size of the deferred queue afterwards.
     */
    public function defer(array $urls): int
    {
        $pending = $this->loadDeferred();

        foreach ($urls as $url) {
            $url = $this->canonicalize((string) $url);
            if ($url === '' || isset($pending[$url])) {
                continue;
            }
            if (count($pending) >= self::MAX_DEFERRED_URLS) {
                $this->logger->warning('TransparentEdge: warm-up queue full, dropping the rest', [
                    'limit' => self::MAX_DEFERRED_URLS,
                ]);
                break;
            }
            $pending[$url] = 1;
        }

        $this->saveDeferred($pending);

        return count($pending);
    }

    /**
     * Move whatever is in the in-memory queue to the deferred one.
     *
     * Lets the schedule* helpers stay as they are: they fill the queue, the
     * caller decides whether that queue goes out now (CLI, cron) or waits
     * for the next drain (anything triggered by an edit).
     *
     * @return int Size of the deferred queue afterwards.
     */
    public function deferQueue(): int
    {
        $urls = $this->queue;
        $this->queue = [];

        return $this->defer($urls);
    }

    /**
     * Warm everything left by defer() since the last drain.
     *
     * @return array {total, success, failed, calls}
     */
    public function drainDeferred(): array
    {
        $vacio = ['total' => 0, 'success' => 0, 'failed' => 0, 'calls' => 0, 'skipped' => 0];

        if ($this->cache->load(self::DRAIN_LOCK_KEY)) {
            // Another drain is still running. Leave the queue alone — the
            // next tick picks it up — and do NOT stamp the heartbeat: a
            // drain that did nothing is not proof the cron is healthy.
            $this->logger->info('TransparentEdge: warm-up drain already in progress, skipping this run');

            return $vacio + ['locked' => true];
        }

        $this->cache->save('1', self::DRAIN_LOCK_KEY, [self::CACHE_TAG], self::DRAIN_LOCK_TTL);

        try {
            $pending = $this->loadDeferred();
            if ($pending === []) {
                // Still a heartbeat: an idle drain proves the cron is alive.
                $this->recordDrain(0, 0);

                return $vacio;
            }

            // Clear first. A drain that dies mid-flight must not leave the
            // queue behind to be retried for ever against an origin already
            // in trouble.
            $this->cache->remove(self::DEFERRED_CACHE_KEY);

            $this->queueUrls(array_keys($pending));

            $result = $this->processQueue();
            $this->recordDrain(count($pending), $result['total']);

            return $result;
        } finally {
            $this->cache->remove(self::DRAIN_LOCK_KEY);
        }
    }

    /**
     * Stamp the heartbeat Diagnostics watches.
     */
    private function recordDrain(int $pending, int $warmed): void
    {
        $this->cache->save(
            (string) json_encode([
                'at'      => time(),
                'pending' => $pending,
                'warmed'  => $warmed,
            ]),
            Diagnostics::WARMUP_HEARTBEAT_KEY,
            [self::CACHE_TAG],
            self::HEARTBEAT_TTL
        );
    }

    /**
     * Number of URLs waiting for the next drain.
     */
    public function getDeferredSize(): int
    {
        return count($this->loadDeferred());
    }

    /**
     * @return array<string,int> URL => 1
     */
    private function loadDeferred(): array
    {
        $raw = $this->cache->load(self::DEFERRED_CACHE_KEY);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,int> $pending
     */
    private function saveDeferred(array $pending): void
    {
        $this->cache->save(
            (string) json_encode($pending),
            self::DEFERRED_CACHE_KEY,
            [self::CACHE_TAG],
            self::DEFERRED_TTL
        );
    }

    // ──────────────────────────────────────────────
    // Hourly budget
    // ──────────────────────────────────────────────

    /**
     * How many URLs may still be warmed this clock hour.
     *
     * @return int PHP_INT_MAX when the ceiling is disabled.
     */
    private function getRemainingBudget(): int
    {
        $budget = $this->config->getWarmupHourlyBudget();
        if ($budget <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $budget - $this->getSpentThisHour());
    }

    private function getSpentThisHour(): int
    {
        $raw = $this->cache->load(self::BUDGET_CACHE_PREFIX . gmdate('YmdH'));

        return is_string($raw) && $raw !== '' ? (int) $raw : 0;
    }

    private function recordSpend(int $count): void
    {
        if ($count <= 0 || $this->config->getWarmupHourlyBudget() <= 0) {
            return;
        }

        $key = self::BUDGET_CACHE_PREFIX . gmdate('YmdH');

        // Two drains in the same second can lose a count here. That is
        // acceptable: this is a ceiling against runaway amplification, not
        // an accounting ledger, and the drain itself is serialised by cron.
        $this->cache->save(
            (string) ($this->getSpentThisHour() + $count),
            $key,
            [self::CACHE_TAG],
            7200
        );
    }

    /**
     * Add a URL to the warm-up queue
     *
     * @param string $url
     */
    public function queueUrl(string $url): void
    {
        $url = $this->canonicalize($url);
        if ($url !== '' && !in_array($url, $this->queue, true)) {
            $this->queue[] = $url;
        }
    }

    /**
     * Reduce a URL to the single object the edge actually stores.
     *
     * Strips the fragment, which never reaches the server, and the store
     * decoration parameters Magento adds when switching store views. Without
     * this the same page is warmed several times over as different objects
     * and the real one can still end up cold.
     *
     * @param  string $url
     * @return string Empty when the URL is not usable.
     */
    private function canonicalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }

        // Only ever warm our own hosts.
        //
        // A warm-up URL is handed to the platform as an invalidate+refetch,
        // so a foreign host here would have us asking the CDN to go and
        // fetch somebody else's site. Nothing in the module composes these
        // URLs from user input today, but the custom-URLs setting and the
        // sitemap are both free text, and this is the kind of hole that is
        // cheap to close now and expensive to discover later.
        if (!in_array(strtolower($parts['host']), $this->getOwnHosts(), true)) {
            $this->logger->warning('TransparentEdge: refusing to warm a URL outside this store', [
                'host' => $parts['host'],
            ]);

            return '';
        }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach (self::DECORATIVE_PARAMS as $drop) {
                unset($params[$drop]);
            }
            if ($params !== []) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . ($parts['path'] ?? '/') . $query;
    }

    /**
     * Hosts this installation serves, lower-cased.
     *
     * Every store view, not just the default one: on a multi-store setup the
     * category URLs come back on each store's own domain, and warming them
     * is legitimate.
     *
     * @return array<string>
     */
    private function getOwnHosts(): array
    {
        if ($this->ownHosts !== null) {
            return $this->ownHosts;
        }

        $hosts = [];
        foreach ([$this->config->getBaseUrl()] as $url) {
            $h = parse_url((string) $url, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h);
            }
        }

        try {
            foreach ($this->storeManager->getStores() as $store) {
                $h = parse_url((string) $store->getBaseUrl(), PHP_URL_HOST);
                if (is_string($h) && $h !== '') {
                    $hosts[] = strtolower($h);
                }
            }
        } catch (\Exception $e) {
            // Store list unavailable (CLI edge cases): the configured base
            // URL alone still gives us a usable allowlist.
            $this->logger->debug('TransparentEdge: could not list store hosts: ' . $e->getMessage());
        }

        return $this->ownHosts = array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Add multiple URLs to the warm-up queue
     *
     * @param array $urls
     */
    public function queueUrls(array $urls): void
    {
        foreach ($urls as $url) {
            $this->queueUrl($url);
        }
    }

    /**
     * Schedule warm-up for all active category pages
     *
     * Uses store emulation to ensure category URLs are generated
     * as frontend URLs, not admin URLs. Handles CLI context gracefully
     * where emulation may not be available.
     */
    public function scheduleCategories(?array $categoryIds = null): void
    {
        if ($categoryIds !== null && $categoryIds === []) {
            return;
        }

        $emulationStarted = false;

        try {
            // Switch to frontend context so getUrl() returns frontend URLs
            $defaultStore = $this->storeManager->getDefaultStoreView();
            if ($defaultStore) {
                $this->appEmulation->startEnvironmentEmulation(
                    (int) $defaultStore->getId(),
                    Area::AREA_FRONTEND,
                    true
                );
                $emulationStarted = true;
            }

            $collection = $this->categoryCollectionFactory->create();
            $collection->addIsActiveFilter()
                ->addUrlRewriteToResult()
                ->addAttributeToSelect(['url_key', 'url_path'])
                ->setPageSize(self::MAX_URLS_PER_BATCH);

            // Warm only what was invalidated when we know which categories
            // those were. Saving one product used to pull the whole tree.
            if ($categoryIds !== null) {
                $collection->addIdFilter($categoryIds);
            }

            $baseUrl = $this->config->getBaseUrl();

            foreach ($collection as $category) {
                $url = $category->getUrl();
                if ($url) {
                    // If URL is an admin URL (emulation failed), build it manually
                    if (strpos($url, '/admin') !== false || strpos($url, '/key/') !== false) {
                        $urlKey = $category->getUrlKey();
                        if ($urlKey) {
                            $url = $baseUrl . '/' . $urlKey . '.html';
                        } else {
                            continue;
                        }
                    }
                    $this->queueUrl($url);
                }
            }

            if ($emulationStarted) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        } catch (\Exception $e) {
            if ($emulationStarted) {
                try {
                    $this->appEmulation->stopEnvironmentEmulation();
                } catch (\Exception $ignore) {
                }
            }

            $this->logger->warning('TransparentEdge: Could not load categories for warm-up', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Schedule a full warm-up (homepage + categories + custom URLs + sitemap)
     */
    public function scheduleFullWarmup(): void
    {
        $baseUrl = $this->config->getBaseUrl();

        // Homepage
        if ($this->config->isWarmupHomepage()) {
            $this->queueUrl($baseUrl . '/');
        }

        // Categories
        if ($this->config->isWarmupCategories()) {
            $this->scheduleCategories();
        }

        // Custom URLs
        $customUrls = $this->config->getWarmupCustomUrls();
        foreach ($customUrls as $url) {
            // Relative URLs → absolute
            if (strpos($url, 'http') !== 0) {
                $url = $baseUrl . '/' . ltrim($url, '/');
            }
            $this->queueUrl($url);
        }

        // Sitemap
        if ($this->config->isWarmupSitemap()) {
            $this->scheduleSitemapUrls();
        }
    }

    /**
     * Parse sitemap and queue URLs for warm-up
     */
    private function scheduleSitemapUrls(): void
    {
        $sitemapUrl = $this->config->getWarmupSitemapUrl();
        if (empty($sitemapUrl)) {
            // Try to find Magento's default sitemap
            $sitemapUrl = $this->config->getBaseUrl() . '/sitemap.xml';
        }

        $xml = $this->fetchSitemapXml($sitemapUrl);
        if ($xml === null) {
            return;
        }

        $count = 0;
        foreach ($xml->url as $url) {
            if ($count >= self::MAX_URLS_PER_BATCH) {
                break;
            }
            $loc = (string) $url->loc;
            if (!empty($loc)) {
                $this->queueUrl($loc);
                $count++;
            }
        }

        // Handle sitemap index files
        foreach ($xml->sitemap as $sitemap) {
            $loc = (string) $sitemap->loc;
            if (!empty($loc) && $count < self::MAX_URLS_PER_BATCH) {
                $this->parseSitemapFile($loc, $count);
            }
        }
    }

    /**
     * Read a sitemap over HTTP.
     *
     * This is the one request the warm-up still makes to the site, and it
     * goes through the CDN like any other. If Bot Management is enabled it
     * will be answered with 403, which used to leave the warm-up silently
     * empty, so that case is named explicitly in the log.
     *
     * @param  string $url
     * @return \SimpleXMLElement|null
     */
    private function fetchSitemapXml(string $url): ?\SimpleXMLElement
    {
        try {
            $client = $this->clientFactory->create(['config' => [
                'timeout'     => 15,
                'http_errors' => false,
                'verify'      => $this->config->isWarmupSslVerify(),
                'headers'     => ['User-Agent' => 'TransparentEdge-Magento/' . Config::VERSION],
            ]]);

            $response = $client->request('GET', $url);
            $status   = $response->getStatusCode();

            if ($status === 403) {
                $this->logger->warning(
                    'TransparentEdge: the sitemap was refused with 403. Bot Management usually answers '
                    . 'automated requests this way. Allow the origin IP for this path, or point the '
                    . 'warm-up at an internal sitemap URL that does not go through the CDN.',
                    ['url' => $url]
                );
                return null;
            }

            if ($status !== 200) {
                $this->logger->warning('TransparentEdge: could not read the sitemap for warm-up', [
                    'url'    => $url,
                    'status' => $status,
                ]);
                return null;
            }

            $xml = @simplexml_load_string($response->getBody()->getContents());

            return $xml === false ? null : $xml;
        } catch (GuzzleException $e) {
            $this->logger->warning('TransparentEdge: could not read the sitemap for warm-up', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse a single sitemap file and add URLs to queue
     *
     * @param string $sitemapUrl
     * @param int    &$count Running count of added URLs
     */
    private function parseSitemapFile(string $sitemapUrl, int &$count): void
    {
        $xml = $this->fetchSitemapXml($sitemapUrl);
        if ($xml === null) {
            return;
        }

        foreach ($xml->url as $url) {
            if ($count >= self::MAX_URLS_PER_BATCH) {
                return;
            }
            $loc = (string) $url->loc;
            if (!empty($loc)) {
                $this->queueUrl($loc);
                $count++;
            }
        }
    }

    /**
     * Hand the queue to the platform to warm.
     *
     * One API call per batch of URLs, each a soft invalidation with refetch.
     * No HTTP request is made to the site itself, so the origin is not asked
     * to render anything, every edge node is warmed rather than just one, and
     * Bot Management is never involved.
     *
     * The whole run is a couple of HTTP POSTs, which is why it no longer
     * matters that it is called from a shutdown handler.
     *
     * @return array{total: int, success: int, failed: int, calls: int}
     */
    public function processQueue(): array
    {
        if (empty($this->queue)) {
            return ['total' => 0, 'success' => 0, 'failed' => 0, 'calls' => 0];
        }

        $urls = array_slice(array_values(array_unique($this->queue)), 0, self::MAX_URLS_PER_BATCH);
        $this->queue = [];

        // The ceiling applies here, where URLs actually leave for the
        // platform, so every source of warming is charged to the same hour:
        // content edits, the CLI command and the periodic sweep alike.
        $remaining = $this->getRemainingBudget();
        $skipped   = 0;
        if (count($urls) > $remaining) {
            $skipped = count($urls) - $remaining;
            $urls    = array_slice($urls, 0, $remaining);
            $this->logger->warning('TransparentEdge: hourly warm-up budget reached, skipping URLs', [
                'budget'  => $this->config->getWarmupHourlyBudget(),
                'skipped' => $skipped,
            ]);
        }

        if ($urls === []) {
            return ['total' => 0, 'success' => 0, 'failed' => 0, 'calls' => 0, 'skipped' => $skipped];
        }

        $this->recordSpend(count($urls));

        $batches = array_chunk($urls, self::URLS_PER_API_CALL);

        // Requests per second allowed against the API. Honoured between
        // batches; a single batch never needs pacing.
        $perSecond = max(1, $this->config->getWarmupRateLimit());
        $gap       = (int) round(1000000 / $perSecond);

        $success = 0;
        $failed  = 0;
        $calls   = 0;

        foreach ($batches as $i => $batch) {
            if ($i > 0) {
                usleep($gap);
            }

            $result = $this->apiClient->warmUrls($batch);
            $calls++;

            if ($result['success']) {
                $success += count($batch);
                if ($this->config->shouldLog('debug')) {
                    $this->logger->debug('TransparentEdge: Warm-up batch queued', [
                        'count' => count($batch),
                        'first' => $batch[0] ?? '',
                    ]);
                }
            } else {
                $failed += count($batch);
                $this->logger->warning('TransparentEdge: Warm-up batch failed', [
                    'count'   => count($batch),
                    'status'  => $result['status'] ?? 0,
                    'message' => $result['message'] ?? '',
                ]);
            }
        }

        $this->logger->info('TransparentEdge: Warm-up completed', [
            'total'   => count($urls),
            'success' => $success,
            'failed'  => $failed,
            'calls'   => $calls,
        ]);

        return [
            'total'   => count($urls),
            'success' => $success,
            'failed'  => $failed,
            'calls'   => $calls,
            'skipped' => $skipped,
        ];
    }

    /**
     * Get the current queue size
     *
     * @return int
     */
    public function getQueueSize(): int
    {
        return count($this->queue);
    }

    /**
     * Get the queued URLs (for debugging)
     *
     * @return array
     */
    public function getQueue(): array
    {
        return $this->queue;
    }
}
