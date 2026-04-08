<?php
/**
 * Setup Wizard Block
 *
 * Provides configuration data and URLs to the wizard template.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Block\Adminhtml\Wizard;

use TransparentEdge\CDN\Model\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Setup extends Template
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param Context $context
     * @param Config  $config
     * @param array   $data
     */
    public function __construct(
        Context $context,
        Config  $config,
        array   $data = []
    ) {
        $this->config = $config;
        parent::__construct($context, $data);
    }

    /**
     * Get the AJAX save URL
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('transparentedge/wizard/save');
    }

    /**
     * Get the configuration page URL
     */
    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit/section/transparentedge');
    }

    /**
     * Get the dashboard URL
     */
    public function getDashboardUrl(): string
    {
        $companyId = $this->config->getCompanyId();
        return $companyId
            ? "https://dashboard.transparentcdn.com/{$companyId}/invalidation"
            : 'https://dashboard.transparentcdn.com';
    }

    /**
     * Check if the module is already configured
     */
    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Check if the wizard was already completed
     */
    public function isWizardCompleted(): bool
    {
        return (bool) $this->_scopeConfig->getValue('transparentedge/general/wizard_completed');
    }

    /**
     * Get existing company ID (for pre-fill)
     */
    public function getCompanyId(): string
    {
        return $this->config->getCompanyId();
    }

    /**
     * Get existing client ID (for pre-fill)
     */
    public function getClientId(): string
    {
        return $this->config->getClientId();
    }

    /**
     * Get the store base URL
     */
    public function getStoreBaseUrl(): string
    {
        return $this->config->getBaseUrl();
    }
}
