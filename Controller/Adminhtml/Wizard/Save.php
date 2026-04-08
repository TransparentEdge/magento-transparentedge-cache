<?php
/**
 * Wizard Save controller - saves configuration from the setup wizard
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Controller\Adminhtml\Wizard;

use TransparentEdge\CDN\Api\ApiClient;
use TransparentEdge\CDN\Model\Config;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Save extends Action
{
    public const ADMIN_RESOURCE = 'TransparentEdge_CDN::configuration';

    private WriterInterface $configWriter;
    private TypeListInterface $cacheTypeList;
    private ApiClient $apiClient;
    private JsonFactory $jsonFactory;

    public function __construct(
        Context           $context,
        WriterInterface   $configWriter,
        TypeListInterface $cacheTypeList,
        ApiClient         $apiClient,
        JsonFactory       $jsonFactory
    ) {
        $this->configWriter  = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->apiClient     = $apiClient;
        $this->jsonFactory   = $jsonFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $params = $this->getRequest()->getParams();
        $action = $params['wizard_action'] ?? 'save';

        try {
            switch ($action) {
                case 'test_connection':
                    return $result->setData($this->testConnection($params));

                case 'save_credentials':
                    return $result->setData($this->saveCredentials($params));

                case 'save_cache':
                    return $result->setData($this->saveCacheSettings($params));

                case 'save_features':
                    return $result->setData($this->saveFeatures($params));

                case 'activate':
                    return $result->setData($this->activate($params));

                default:
                    return $result->setData(['success' => false, 'message' => 'Unknown action']);
            }
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Test API connection with provided credentials
     */
    private function testConnection(array $params): array
    {
        // Temporarily save credentials for testing
        $this->configWriter->save(Config::COMPANY_ID, $params['company_id'] ?? '');
        $this->configWriter->save(Config::CLIENT_ID, $params['client_id'] ?? '');
        $this->configWriter->save(Config::CLIENT_SECRET, $params['client_secret'] ?? '');
        $this->flushConfig();

        // Clear any cached token
        $this->apiClient->clearToken();

        return $this->apiClient->testConnection();
    }

    /**
     * Save API credentials (Step 1)
     */
    private function saveCredentials(array $params): array
    {
        $this->configWriter->save(Config::COMPANY_ID, $params['company_id'] ?? '');
        $this->configWriter->save(Config::CLIENT_ID, $params['client_id'] ?? '');
        $this->configWriter->save(Config::CLIENT_SECRET, $params['client_secret'] ?? '');
        $this->flushConfig();

        return ['success' => true, 'message' => 'Credentials saved.'];
    }

    /**
     * Save cache settings (Step 2)
     */
    private function saveCacheSettings(array $params): array
    {
        $preset = $params['preset'] ?? 'standard';

        switch ($preset) {
            case 'ecommerce':
                // More conservative TTLs for dynamic ecommerce
                $this->configWriter->save(Config::HTML_TTL, '14400');           // 4h
                $this->configWriter->save(Config::HTML_BROWSER_TTL, '600');     // 10min
                $this->configWriter->save(Config::STALE_WHILE_REVALIDATE, '86400');
                $this->configWriter->save(Config::STALE_IF_ERROR, '86400');
                break;

            case 'aggressive':
                // Maximum caching for high-traffic sites with infrequent changes
                $this->configWriter->save(Config::HTML_TTL, '172800');          // 48h
                $this->configWriter->save(Config::HTML_BROWSER_TTL, '3600');    // 1h
                $this->configWriter->save(Config::STALE_WHILE_REVALIDATE, '172800');
                $this->configWriter->save(Config::STALE_IF_ERROR, '172800');
                break;

            case 'standard':
            default:
                // Balanced defaults
                $this->configWriter->save(Config::HTML_TTL, '86400');           // 24h
                $this->configWriter->save(Config::HTML_BROWSER_TTL, '1800');    // 30min
                $this->configWriter->save(Config::STALE_WHILE_REVALIDATE, '86400');
                $this->configWriter->save(Config::STALE_IF_ERROR, '86400');
                break;
        }

        // These are the same for all presets
        $this->configWriter->save(Config::STATIC_TTL, '2592000');          // 30d
        $this->configWriter->save(Config::STATIC_BROWSER_TTL, '86400');    // 1d
        $this->configWriter->save(Config::SOFT_PURGE, '1');

        $this->flushConfig();
        return ['success' => true, 'message' => 'Cache settings saved.'];
    }

    /**
     * Save feature toggles (Step 3)
     */
    private function saveFeatures(array $params): array
    {
        // Warm-up
        $this->configWriter->save(Config::WARMUP_ENABLED, $params['warmup_enabled'] ?? '1');
        $this->configWriter->save(Config::WARMUP_HOMEPAGE, $params['warmup_homepage'] ?? '1');
        $this->configWriter->save(Config::WARMUP_CATEGORIES, $params['warmup_categories'] ?? '1');
        $this->configWriter->save(Config::WARMUP_SITEMAP, $params['warmup_sitemap'] ?? '0');
        $this->configWriter->save(Config::WARMUP_RATE_LIMIT, $params['warmup_rate_limit'] ?? '3');

        // i3
        $this->configWriter->save(Config::I3_ENABLED, $params['i3_enabled'] ?? '0');
        $this->configWriter->save(Config::I3_AUTO_WEBP, $params['i3_auto_webp'] ?? '1');
        $this->configWriter->save(Config::I3_QUALITY, $params['i3_quality'] ?? '85');

        // Admin bypass
        $this->configWriter->save(Config::ADMIN_BYPASS, $params['admin_bypass'] ?? '1');

        $this->flushConfig();
        return ['success' => true, 'message' => 'Features saved.'];
    }

    /**
     * Activate the module (Step 4)
     */
    private function activate(array $params): array
    {
        $this->configWriter->save(Config::ENABLED, '1');

        // Mark wizard as completed
        $this->configWriter->save('transparentedge/general/wizard_completed', '1');

        $this->flushConfig();
        return ['success' => true, 'message' => 'Transparent Edge CDN is now active!'];
    }

    /**
     * Flush config cache so changes take effect immediately
     */
    private function flushConfig(): void
    {
        $this->cacheTypeList->cleanType('config');
    }
}
