<?php
/**
 * Observer for admin cache flush events
 *
 * Triggers a full CDN purge when the admin flushes all cache
 * or flushes Magento cache from System > Cache Management.
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
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

class CacheFlushObserver implements ObserverInterface
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Invalidator
     */
    private Invalidator $invalidator;

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $messageManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config           $config
     * @param Invalidator      $invalidator
     * @param ManagerInterface $messageManager
     * @param LoggerInterface  $logger
     */
    public function __construct(
        Config           $config,
        Invalidator      $invalidator,
        ManagerInterface $messageManager,
        LoggerInterface  $logger
    ) {
        $this->config         = $config;
        $this->invalidator    = $invalidator;
        $this->messageManager = $messageManager;
        $this->logger         = $logger;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $event = $observer->getEvent()->getName();

        $this->logger->info('TransparentEdge: Cache flush event triggered', ['event' => $event]);

        $this->invalidator->queueFullPurge();
        $this->messageManager->addSuccessMessage(
            __('Transparent Edge CDN cache purge has been queued.')
        );
    }
}
