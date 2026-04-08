<?php
/**
 * Admin notification block
 *
 * Shows a dismissible notice in the admin panel when the plugin is installed
 * but not yet configured, guiding the user to the Setup Wizard.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Block\Adminhtml;

use TransparentEdge\CDN\Model\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Notification extends Template
{
    /**
     * @var Config
     */
    private Config $config;

    public function __construct(Context $context, Config $config, array $data = [])
    {
        $this->config = $config;
        parent::__construct($context, $data);
    }

    /**
     * Should the notification be displayed?
     */
    public function shouldShow(): bool
    {
        // Show if module is not configured and wizard wasn't completed
        $wizardDone = (bool) $this->_scopeConfig->getValue('transparentedge/general/wizard_completed');
        return !$wizardDone && !$this->config->isConfigured();
    }

    /**
     * Get the wizard URL
     */
    public function getWizardUrl(): string
    {
        return $this->getUrl('transparentedge/wizard/index');
    }
}
