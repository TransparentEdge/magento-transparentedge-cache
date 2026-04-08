<?php
/**
 * Plugin for mass attribute update controller
 *
 * Intercepts the mass attribute update Save controller to queue CDN
 * invalidation for all affected products. In Magento 2.4.7+ the actual
 * attribute update is processed asynchronously via message queue, but
 * the controller still has access to the product IDs via the helper.
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
use Magento\Catalog\Helper\Product\Edit\Action\Attribute as AttributeHelper;
use Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute\Save as SaveController;
use Psr\Log\LoggerInterface;

class MassAttributeSavePlugin
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
     * After the mass attribute save controller executes, queue CDN invalidation
     *
     * @param SaveController $subject
     * @param mixed          $result
     * @return mixed
     */
    public function afterExecute(SaveController $subject, $result)
    {
        if (!$this->config->isConfigured()) {
            return $result;
        }

        try {
            $productIds = $this->attributeHelper->getProductIds();

            if (!empty($productIds)) {
                $tags = [];
                foreach ($productIds as $productId) {
                    $tags[] = 'cat_p_' . $productId;
                }
                $tags[] = 'cat_p';

                $this->invalidator->queueTags($tags);

                $this->logger->info('TransparentEdge: Mass attribute update invalidation queued', [
                    'product_count' => count($productIds),
                    'product_ids'   => array_slice($productIds, 0, 20),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('TransparentEdge: Mass attribute update observer failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }
}
