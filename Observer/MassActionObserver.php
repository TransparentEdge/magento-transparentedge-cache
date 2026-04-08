<?php
/**
 * Mass action observer for CDN invalidation
 *
 * Handles mass product actions that use a different flow than individual saves:
 * - Catalog → Products → Actions → Update Attributes → Save
 * - Catalog → Products → Actions → Change Status
 *
 * These actions store product IDs in the Catalog Attribute Helper and process
 * updates via message queue, so they don't fire catalog_product_save_after.
 * Magento's own cache-invalidate module uses the same controller postdispatch
 * approach.
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
use Magento\Catalog\Helper\Product\Edit\Action\Attribute as AttributeHelper;
use Psr\Log\LoggerInterface;

class MassActionObserver implements ObserverInterface
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
     * @var AttributeHelper
     */
    private AttributeHelper $attributeHelper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config          $config
     * @param Invalidator     $invalidator
     * @param AttributeHelper $attributeHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config          $config,
        Invalidator     $invalidator,
        AttributeHelper $attributeHelper,
        LoggerInterface $logger
    ) {
        $this->config          = $config;
        $this->invalidator     = $invalidator;
        $this->attributeHelper = $attributeHelper;
        $this->logger          = $logger;
    }

    /**
     * Handle mass product action postdispatch
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $productIds = $this->attributeHelper->getProductIds();

        if (empty($productIds)) {
            return;
        }

        $tags = [];
        foreach ($productIds as $productId) {
            $tags[] = 'cat_p_' . $productId;
        }
        $tags[] = 'cat_p';

        $this->invalidator->queueTags($tags);

        $this->logger->info('TransparentEdge: Mass action invalidation queued', [
            'product_count' => count($productIds),
            'product_ids'   => array_slice($productIds, 0, 20),
        ]);
    }
}
