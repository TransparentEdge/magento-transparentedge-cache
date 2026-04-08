<?php
/**
 * Setup Wizard controller
 *
 * Renders the guided setup wizard for first-time configuration.
 * Accessible from the admin sidebar: Transparent Edge → Setup Wizard
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Controller\Adminhtml\Wizard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'TransparentEdge_CDN::configuration';

    /**
     * @var PageFactory
     */
    private PageFactory $pageFactory;

    public function __construct(Context $context, PageFactory $pageFactory)
    {
        $this->pageFactory = $pageFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('TransparentEdge_CDN::wizard');
        $page->getConfig()->getTitle()->prepend(__('Transparent Edge CDN — Setup Wizard'));
        return $page;
    }
}
