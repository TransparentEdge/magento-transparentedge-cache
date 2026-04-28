<?php
/**
 * Cache invalidation engine for Transparent Edge CDN
 *
 * Collects Magento cache tags during the request lifecycle, batches them,
 * translates to Surrogate-Keys, and sends a single invalidation request
 * to the TE API on shutdown. Triggers warm-up after invalidation.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use TransparentEdge\CDN\Api\ApiClient;
use Psr\Log\LoggerInterface;

class Invalidator
{
    /**
     * Maximum tags per API request
     */
    private const MAX_TAGS_PER_REQUEST = 100;

    /**
     * Maximum pending tags before switching to full purge.
     * Prevents memory issues during massive imports and reduces
     * API calls (1 full ban vs hundreds of batched tag invalidations).
     */
    private const FULL_PURGE_THRESHOLD = 5000;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var TagResolver
     */
    private TagResolver $tagResolver;

    /**
     * @var Warmup
     */
    private Warmup $warmup;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Accumulated Magento tags to invalidate (batched for shutdown)
     *
     * @var array
     */
    private array $pendingTags = [];

    /**
     * Whether a full purge was requested
     *
     * @var bool
     */
    private bool $fullPurge = false;

    /**
     * Whether the shutdown handler is registered
     *
     * @var bool
     */
    private bool $shutdownRegistered = false;

    /**
     * @param Config          $config
     * @param ApiClient       $apiClient
     * @param TagResolver     $tagResolver
     * @param Warmup          $warmup
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config          $config,
        ApiClient       $apiClient,
        TagResolver     $tagResolver,
        Warmup          $warmup,
        LoggerInterface $logger
    ) {
        $this->config      = $config;
        $this->apiClient   = $apiClient;
        $this->tagResolver = $tagResolver;
        $this->warmup      = $warmup;
        $this->logger      = $logger;
    }

    /**
     * Queue Magento cache tags for invalidation (batched on shutdown)
     *
     * @param array $magentoTags Magento cache tag strings
     */
    public function queueTags(array $magentoTags): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $this->pendingTags = array_merge($this->pendingTags, $magentoTags);
        $this->registerShutdown();

        // Safety valve: if too many tags accumulate (massive import/reindex),
        // switch to a single full ban instead of hundreds of batched API calls.
        if (count($this->pendingTags) > self::FULL_PURGE_THRESHOLD) {
            $this->logger->warning('TransparentEdge: Tag threshold exceeded, converting to full ban', [
                'pending_tags' => count($this->pendingTags),
                'threshold'    => self::FULL_PURGE_THRESHOLD,
            ]);
            $this->pendingTags = [];
            $this->fullPurge = true;
            return;
        }

        $this->logger->debug('TransparentEdge: Queued tags for invalidation', [
            'new_tags'      => count($magentoTags),
            'total_pending' => count($this->pendingTags),
        ]);
    }

    /**
     * Request a full cache purge
     */
    public function queueFullPurge(): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $this->fullPurge = true;
        $this->registerShutdown();

        $this->logger->info('TransparentEdge: Full purge queued');
    }

    /**
     * Check if there are surgical tags pending (queued by entity save observers)
     *
     * Used by AutoFlushCacheObserver to determine if the current flush is
     * from an entity save (surgical) or a structural change (full purge needed).
     *
     * @return bool
     */
    public function hasPendingTags(): bool
    {
        return !empty($this->pendingTags);
    }

    /**
     * Execute all pending invalidations immediately
     *
     * Called either from the shutdown handler or directly for CLI commands.
     *
     * @return array Summary of results
     */
    public function flush(): array
    {
        // In FastCGI environments, send the response to the client first
        // so API calls don't block the user. Also acts as a safety net:
        // if PHP-FPM kills the process before shutdown, at least the
        // response was already sent.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $results = [];

        if ($this->fullPurge) {
            $results[] = $this->executeFullPurge();
            $this->fullPurge    = false;
            $this->pendingTags  = [];
            return $results;
        }

        if (empty($this->pendingTags)) {
            return $results;
        }

        // Deduplicate pending tags
        $uniqueTags = array_unique($this->pendingTags);
        $this->pendingTags = [];

        // Resolve Magento tags → TE Surrogate-Keys
        $surrogateKeys = $this->tagResolver->resolve($uniqueTags);

        if (empty($surrogateKeys)) {
            $this->logger->debug('TransparentEdge: No Surrogate-Keys resolved from pending tags');
            return $results;
        }

        // Batch if necessary
        $batches = array_chunk($surrogateKeys, self::MAX_TAGS_PER_REQUEST);

        foreach ($batches as $batch) {
            $result = $this->apiClient->invalidateByTags($batch);
            $results[] = $result;

            if (!$result['success']) {
                $this->logger->error('TransparentEdge: Tag invalidation failed', [
                    'tags'    => $batch,
                    'status'  => $result['status'],
                    'message' => $result['message'],
                ]);
            }
        }

        // Trigger warm-up after successful invalidation
        if ($this->config->isWarmupEnabled() && !empty(array_filter($results, fn($r) => $r['success']))) {
            $this->scheduleWarmup($surrogateKeys);
        }

        $this->logger->info('TransparentEdge: Invalidation completed', [
            'magento_tags'    => count($uniqueTags),
            'surrogate_keys'  => count($surrogateKeys),
            'batches'         => count($batches),
            'success'         => count(array_filter($results, fn($r) => $r['success'])),
        ]);

        return $results;
    }

    /**
     * Invalidate specific entities immediately (no batching)
     *
     * @param  string $entityType  product|category|page|block
     * @param  array  $entityIds   Array of entity IDs
     * @return array
     */
    public function invalidateEntities(string $entityType, array $entityIds): array
    {
        $tags = [];
        $prefix = $this->getEntityTagPrefix($entityType);

        foreach ($entityIds as $id) {
            $tags[] = $prefix . '_' . $id;
        }

        $surrogateKeys = $this->tagResolver->resolve($tags);

        if (empty($surrogateKeys)) {
            return ['success' => false, 'message' => 'No keys resolved'];
        }

        return $this->apiClient->invalidateByTags($surrogateKeys);
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /**
     * Execute a full cache purge via the API
     *
     * @return array
     */
    private function executeFullPurge(): array
    {
        // Full ban: uses tag_invalidate with te-all (falls back to URL purge)
        $result = $this->apiClient->purgeAll();

        if ($result['success']) {
            $this->logger->info('TransparentEdge: Full cache ban executed successfully');

            // Schedule full warm-up
            if ($this->config->isWarmupEnabled()) {
                $this->warmup->scheduleFullWarmup();
            }
        } else {
            $this->logger->error('TransparentEdge: Full cache ban failed', [
                'status'  => $result['status'],
                'message' => $result['message'],
            ]);
        }

        return $result;
    }

    /**
     * Schedule warm-up after tag invalidation
     *
     * @param array $invalidatedKeys Surrogate-Keys that were invalidated
     */
    private function scheduleWarmup(array $invalidatedKeys): void
    {
        // Always warm the homepage
        if ($this->config->isWarmupHomepage()) {
            $this->warmup->queueUrl($this->config->getBaseUrl() . '/');
        }

        // If categories were invalidated, warm category pages
        if ($this->config->isWarmupCategories()) {
            $categoryKeys = array_filter($invalidatedKeys, function ($key) {
                return strpos($key, 'category-') === 0 || $key === 'te-categories';
            });

            if (!empty($categoryKeys)) {
                $this->warmup->scheduleCategories();
            }
        }

        // Process queued warm-up URLs
        $this->warmup->processQueue();
    }

    /**
     * Get Magento tag prefix for an entity type
     *
     * @param  string $entityType
     * @return string
     */
    private function getEntityTagPrefix(string $entityType): string
    {
        $map = [
            'product'  => 'cat_p',
            'category' => 'cat_c',
            'page'     => 'cms_p',
            'block'    => 'cms_b',
        ];

        return $map[$entityType] ?? $entityType;
    }

    /**
     * Register the shutdown handler to flush pending invalidations
     */
    private function registerShutdown(): void
    {
        if (!$this->shutdownRegistered) {
            register_shutdown_function([$this, 'flush']);
            $this->shutdownRegistered = true;
        }
    }
}
