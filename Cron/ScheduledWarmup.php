<?php
/**
 * Scheduled warm-up cron job
 *
 * Runs periodically to keep the CDN cache warm with key pages.
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

class ScheduledWarmup
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Warmup
     */
    private Warmup $warmup;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config          $config
     * @param Warmup          $warmup
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config          $config,
        Warmup          $warmup,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->warmup = $warmup;
        $this->logger = $logger;
    }

    /**
     * Execute scheduled warm-up
     */
    public function execute(): void
    {
        if (!$this->config->isConfigured() || !$this->config->isWarmupEnabled()) {
            return;
        }

        $this->logger->info('TransparentEdge: Starting scheduled warm-up');

        $this->warmup->scheduleFullWarmup();
        $result = $this->warmup->processQueue();

        $this->logger->info('TransparentEdge: Scheduled warm-up completed', $result);
    }
}
