<?php
/**
 * Drains the deferred warm-up queue.
 *
 * Content edits no longer warm anything inline: they leave URLs in a shared
 * queue and return. This job empties it.
 *
 * Running on a short cycle is what makes the design safe. Everything queued
 * since the last run is deduplicated into one set of distinct URLs and warmed
 * once, so a burst of edits costs the origin the same as a single edit
 * touching the same pages. Whatever is left over the hourly budget is
 * dropped rather than carried forward: the edge still revalidates those
 * pages on first request thanks to soft purge and stale-while-revalidate.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Cron;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Warmup;
use Psr\Log\LoggerInterface;

class DeferredWarmup
{
    private Config $config;
    private Warmup $warmup;
    private LoggerInterface $logger;

    public function __construct(
        Config          $config,
        Warmup          $warmup,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->warmup = $warmup;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isConfigured() || !$this->config->isWarmupEnabled()) {
            return;
        }

        $result = $this->warmup->drainDeferred();

        if ($result['total'] === 0 && ($result['skipped'] ?? 0) === 0) {
            return;
        }

        $this->logger->info('TransparentEdge: deferred warm-up drained', $result);
    }
}
