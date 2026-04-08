<?php
/**
 * Plugin to intercept Magento's internal cache type cleaning
 *
 * When Magento cleans the full_page cache type (from Cache Management),
 * this plugin triggers a full CDN purge. Has a recursion guard to prevent
 * double-purging when the AutoFlushCacheObserver triggers a clean.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Plugin;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Invalidator;
use Magento\Framework\App\Cache\TypeListInterface;
use Psr\Log\LoggerInterface;

class CacheInvalidatePlugin
{
    /**
     * Static flag to prevent recursion when auto-flush triggers cleanType
     *
     * @var bool
     */
    public static bool $autoFlushInProgress = false;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Invalidator
     */
    private Invalidator $invalidator;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config          $config
     * @param Invalidator     $invalidator
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config          $config,
        Invalidator     $invalidator,
        LoggerInterface $logger
    ) {
        $this->config      = $config;
        $this->invalidator = $invalidator;
        $this->logger      = $logger;
    }

    /**
     * After a cache type is cleaned, queue the tags for TE invalidation
     *
     * Only fires for manual flushes (Cache Management), not for auto-flushes.
     *
     * @param  TypeListInterface $subject
     * @param  mixed             $result
     * @param  string            $typeCode
     * @return mixed
     */
    public function afterCleanType(TypeListInterface $subject, $result, $typeCode)
    {
        if (!$this->config->isConfigured()) {
            return $result;
        }

        // Skip if this was triggered by our own auto-flush
        if (self::$autoFlushInProgress) {
            return $result;
        }

        // If full_page cache is cleaned manually, trigger full CDN purge
        if ($typeCode === 'full_page') {
            $this->logger->info('TransparentEdge: full_page cache type cleaned manually, queuing full purge');
            $this->invalidator->queueFullPurge();
        }

        return $result;
    }
}
