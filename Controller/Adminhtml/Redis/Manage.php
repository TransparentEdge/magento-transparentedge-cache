<?php
/**
 * Redis management AJAX controller
 *
 * Handles all Redis operations from the admin panel:
 * - detect: Auto-detect Redis instances
 * - status: Get current Redis configuration status
 * - enable: Enable Redis for a specific backend
 * - disable: Disable Redis for a specific backend
 * - rollback: Restore env.php from backup
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Controller\Adminhtml\Redis;

use TransparentEdge\CDN\Model\Redis\RedisDetector;
use TransparentEdge\CDN\Model\Redis\RedisManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Manage extends Action
{
    public const ADMIN_RESOURCE = 'TransparentEdge_CDN::configuration';

    private RedisDetector $detector;
    private RedisManager $manager;
    private JsonFactory $jsonFactory;

    public function __construct(
        Context       $context,
        RedisDetector $detector,
        RedisManager  $manager,
        JsonFactory   $jsonFactory
    ) {
        $this->detector    = $detector;
        $this->manager     = $manager;
        $this->jsonFactory = $jsonFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $params = $this->getRequest()->getParams();
        $action = $params['redis_action'] ?? '';

        try {
            switch ($action) {
                case 'detect':
                    $host = !empty($params['host']) ? $params['host'] : null;
                    $port = !empty($params['port']) ? (int) $params['port'] : null;
                    $password = !empty($params['password']) ? $params['password'] : null;
                    $data = $this->detector->detect($host, $port, $password);
                    $data['status'] = $this->manager->getStatus();
                    $data['recommended_dbs'] = $this->detector->recommendDatabases($data['databases'] ?? []);
                    return $result->setData($data);

                case 'status':
                    return $result->setData([
                        'success' => true,
                        'status'  => $this->manager->getStatus(),
                    ]);

                case 'enable':
                    $type     = $params['type'] ?? '';
                    $host     = $params['host'] ?? '127.0.0.1';
                    $port     = (int) ($params['port'] ?? 6379);
                    $password = $params['password'] ?? '';
                    $database = (int) ($params['database'] ?? 0);

                    if (!in_array($type, ['cache', 'fpc', 'session'], true)) {
                        return $result->setData(['success' => false, 'message' => 'Invalid type']);
                    }

                    if ($type === 'cache') {
                        $response = $this->manager->enableCache($host, $port, $password, $database);
                    } elseif ($type === 'fpc') {
                        $response = $this->manager->enableFpc($host, $port, $password, $database);
                    } else {
                        $response = $this->manager->enableSession($host, $port, $password, $database);
                    }

                    $response['status'] = $this->manager->getStatus();
                    return $result->setData($response);

                case 'disable':
                    $type = $params['type'] ?? '';
                    if (!in_array($type, ['cache', 'fpc', 'session'], true)) {
                        return $result->setData(['success' => false, 'message' => 'Invalid type']);
                    }
                    $response = $this->manager->disable($type);
                    $response['status'] = $this->manager->getStatus();
                    return $result->setData($response);

                case 'rollback':
                    $response = $this->manager->rollback();
                    $response['status'] = $this->manager->getStatus();
                    return $result->setData($response);

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
}
