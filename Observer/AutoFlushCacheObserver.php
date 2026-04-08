<?php
/**
 * Auto-flush observer for Magento internal cache + CDN purge
 *
 * After any admin action, this observer:
 * 1. Checks for invalidated Magento cache types and flushes them automatically
 * 2. If full_page was flushed AND no surgical CDN invalidation is pending
 *    (i.e., it's a theme/config change, not an entity save), triggers a
 *    full CDN purge.
 *
 * This eliminates the "One or more cache types are invalidated" banner
 * and ensures CDN consistency for structural changes like theme switches.
 *
 * Entity saves (product, category, CMS) are handled surgically by
 * InvalidateTagsObserver and do NOT trigger a full CDN purge here.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Observer;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Invalidator;
use TransparentEdge\CDN\Plugin\CacheInvalidatePlugin;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Psr\Log\LoggerInterface;

class AutoFlushCacheObserver implements ObserverInterface
{
    /**
     * Cache types that should be auto-flushed when invalidated
     */
    private const AUTO_FLUSH_TYPES = [
        'full_page',
        'block_html',
        'layout',
        'translate',
        'config',
        'collections',
    ];

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var TypeListInterface
     */
    private TypeListInterface $cacheTypeList;

    /**
     * @var Invalidator
     */
    private Invalidator $invalidator;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config            $config
     * @param TypeListInterface $cacheTypeList
     * @param Invalidator       $invalidator
     * @param LoggerInterface   $logger
     */
    public function __construct(
        Config            $config,
        TypeListInterface $cacheTypeList,
        Invalidator       $invalidator,
        LoggerInterface   $logger
    ) {
        $this->config        = $config;
        $this->cacheTypeList = $cacheTypeList;
        $this->invalidator   = $invalidator;
        $this->logger        = $logger;
    }

    /**
     * Auto-flush invalidated cache types after any admin action
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $flushed = [];

        // Set the flag so CacheInvalidatePlugin doesn't trigger redundant CDN purge
        CacheInvalidatePlugin::$autoFlushInProgress = true;

        try {
            $invalidTypes = $this->cacheTypeList->getInvalidated();
            foreach ($invalidTypes as $type) {
                $typeCode = $type->getId();
                if (in_array($typeCode, self::AUTO_FLUSH_TYPES, true)) {
                    $this->cacheTypeList->cleanType($typeCode);
                    $flushed[] = $typeCode;
                }
            }
        } finally {
            CacheInvalidatePlugin::$autoFlushInProgress = false;
        }

        if (empty($flushed)) {
            return;
        }

        $this->logger->info('TransparentEdge: Auto-flushed Magento cache types', [
            'types' => $flushed,
        ]);

        // If full_page was flushed, check if CDN needs a full purge.
        // Entity saves (product, category, CMS) are handled surgically by
        // InvalidateTagsObserver — they queue specific tags in the Invalidator.
        // Structural changes (theme, config, design) don't queue tags.
        // So: if full_page was flushed but no surgical tags are pending,
        // this is a structural change and we need a full CDN purge.
        if (in_array('full_page', $flushed, true) && !$this->invalidator->hasPendingTags()) {
            $this->logger->info('TransparentEdge: Structural change detected (no surgical tags pending), queuing full CDN purge');
            $this->invalidator->queueFullPurge();
        }
    }
}
