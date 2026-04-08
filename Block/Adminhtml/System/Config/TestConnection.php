<?php
/**
 * Admin block for Test Connection button
 *
 * Renders a "Test Connection" button in the admin configuration page
 * that performs an AJAX call to validate OAuth2 credentials.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

class TestConnection extends Field
{
    /**
     * @var string
     */
    protected $_template = 'TransparentEdge_CDN::system/config/test_connection.phtml';

    /**
     * Remove scope label and use full row
     *
     * @param  AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Get the AJAX URL for testing the connection
     *
     * @return string
     */
    public function getTestUrl(): string
    {
        return $this->getUrl('transparentedge/cache/testConnection');
    }

    /**
     * Get button HTML
     *
     * @return string
     */
    public function getButtonHtml(): string
    {
        $button = $this->getLayout()->createBlock(\Magento\Backend\Block\Widget\Button::class)
            ->setData([
                'id'    => 'te_test_connection_btn',
                'label' => __('Test Connection'),
                'class' => 'primary',
            ]);

        return $button->toHtml();
    }

    /**
     * Return element HTML
     *
     * @param  AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }
}
