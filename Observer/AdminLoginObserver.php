<?php
/**
 * Admin login observer - sets the CDN bypass cookie
 *
 * When an admin user logs in, a cookie is set that tells the CDN VCL
 * to pass requests through without caching (admin bypass).
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Observer;

use TransparentEdge\CDN\Model\Config;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;
use Magento\Backend\App\ConfigInterface as BackendConfigInterface;
use Magento\Backend\Model\Auth\Session;
use Psr\Log\LoggerInterface;

class AdminLoginObserver implements ObserverInterface
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var CookieManagerInterface
     */
    private CookieManagerInterface $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private CookieMetadataFactory $cookieMetadataFactory;

    /**
     * @var BackendConfigInterface
     */
    private BackendConfigInterface $backendConfig;

    /**
     * @var SessionConfigInterface
     */
    private SessionConfigInterface $sessionConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config                 $config
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory  $cookieMetadataFactory
     * @param BackendConfigInterface $backendConfig
     * @param SessionConfigInterface $sessionConfig
     * @param LoggerInterface        $logger
     */
    public function __construct(
        Config                 $config,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory  $cookieMetadataFactory,
        BackendConfigInterface $backendConfig,
        SessionConfigInterface $sessionConfig,
        LoggerInterface        $logger
    ) {
        $this->config               = $config;
        $this->cookieManager        = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->backendConfig        = $backendConfig;
        $this->sessionConfig        = $sessionConfig;
        $this->logger               = $logger;
    }

    /**
     * Set the CDN bypass cookie on admin login
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isAdminBypassEnabled()) {
            return;
        }

        try {
            $lifetime = (int) $this->backendConfig->getValue(Session::XML_PATH_SESSION_LIFETIME);
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setDuration($lifetime)
                ->setPath('/')
                ->setHttpOnly(false)
                ->setSameSite('Lax');

            $this->cookieManager->setPublicCookie(
                Config::ADMIN_COOKIE_NAME,
                Config::ADMIN_COOKIE_VALUE,
                $metadata
            );

            $this->logger->info('TransparentEdge: Admin bypass cookie set');
        } catch (\Exception $e) {
            $this->logger->error('TransparentEdge: Failed to set admin bypass cookie', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
