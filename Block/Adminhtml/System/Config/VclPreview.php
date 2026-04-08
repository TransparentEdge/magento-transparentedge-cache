<?php
/**
 * Admin block for VCL Preview button
 *
 * Renders a button that shows the generated VCL configuration
 * in a modal dialog within the admin configuration page.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Block\Adminhtml\System\Config;

use TransparentEdge\CDN\Model\VclGenerator;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

class VclPreview extends Field
{
    /**
     * @var string
     */
    protected $_template = 'TransparentEdge_CDN::system/config/vcl_preview.phtml';

    /**
     * @var VclGenerator
     */
    private VclGenerator $vclGenerator;

    /**
     * @param Context      $context
     * @param VclGenerator $vclGenerator
     * @param array        $data
     */
    public function __construct(
        Context      $context,
        VclGenerator $vclGenerator,
        array        $data = []
    ) {
        $this->vclGenerator = $vclGenerator;
        parent::__construct($context, $data);
    }

    /**
     * Remove scope label
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
     * Get the generated VCL code
     *
     * @return string
     */
    public function getVclCode(): string
    {
        return $this->vclGenerator->generateFull();
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
                'id'    => 'te_vcl_preview_btn',
                'label' => __('Show VCL Configuration'),
                'class' => 'secondary',
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
