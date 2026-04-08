<?php
/**
 * Admin AJAX controller for testing API connection
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Controller\Adminhtml\Cache;

use TransparentEdge\CDN\Api\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class TestConnection extends Action
{
    public const ADMIN_RESOURCE = 'TransparentEdge_CDN::configuration';

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var JsonFactory
     */
    private JsonFactory $jsonFactory;

    /**
     * @param Context     $context
     * @param ApiClient   $apiClient
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Context     $context,
        ApiClient   $apiClient,
        JsonFactory $jsonFactory
    ) {
        $this->apiClient   = $apiClient;
        $this->jsonFactory = $jsonFactory;
        parent::__construct($context);
    }

    /**
     * Execute test connection
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        $response = $this->apiClient->testConnection();

        return $result->setData($response);
    }
}
