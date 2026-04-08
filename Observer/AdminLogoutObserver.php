<?php
/**
 * Admin logout observer - removes the CDN bypass cookie
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
use Psr\Log\LoggerInterface;

class AdminLogoutObserver implements ObserverInterface
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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config                 $config
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory  $cookieMetadataFactory
     * @param LoggerInterface        $logger
     */
    public function __construct(
        Config                 $config,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory  $cookieMetadataFactory,
        LoggerInterface        $logger
    ) {
        $this->config               = $config;
        $this->cookieManager        = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->logger               = $logger;
    }

    /**
     * Remove the CDN bypass cookie on admin logout
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            if ($this->cookieManager->getCookie(Config::ADMIN_COOKIE_NAME)) {
                $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setPath('/');

                $this->cookieManager->deleteCookie(Config::ADMIN_COOKIE_NAME, $metadata);
                $this->logger->info('TransparentEdge: Admin bypass cookie removed');
            }
        } catch (\Exception $e) {
            $this->logger->error('TransparentEdge: Failed to remove admin bypass cookie', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
