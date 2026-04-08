<?php
/**
 * Admin controller for manual CDN cache purge
 *
 * Accessible from the sidebar menu (Transparent Edge → Purge CDN Cache)
 * and via AJAX from the configuration page.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Controller\Adminhtml\Cache;

use TransparentEdge\CDN\Api\ApiClient;
use TransparentEdge\CDN\Model\Config;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Purge extends Action
{
    public const ADMIN_RESOURCE = 'TransparentEdge_CDN::purge';

    private ApiClient $apiClient;
    private Config $config;
    private JsonFactory $jsonFactory;

    public function __construct(
        Context     $context,
        ApiClient   $apiClient,
        Config      $config,
        JsonFactory $jsonFactory
    ) {
        $this->apiClient   = $apiClient;
        $this->config      = $config;
        $this->jsonFactory = $jsonFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        if (!$this->config->isConfigured()) {
            $this->messageManager->addErrorMessage(
                __('Transparent Edge CDN is not configured. Please run the Setup Wizard first.')
            );
            return $this->resultRedirectFactory->create()->setPath('transparentedge/wizard/index');
        }

        $response = $this->apiClient->purgeAll();

        if ($response['success']) {
            $this->messageManager->addSuccessMessage(
                __('Transparent Edge CDN cache purged successfully.')
            );
        } else {
            $this->messageManager->addErrorMessage(
                __('Failed to purge Transparent Edge CDN cache: %1', $response['message'])
            );
        }

        // If AJAX request, return JSON
        if ($this->getRequest()->isAjax()) {
            return $this->jsonFactory->create()->setData($response);
        }

        // Otherwise redirect back to cache management
        return $this->resultRedirectFactory->create()->setPath('adminhtml/cache/index');
    }
}
