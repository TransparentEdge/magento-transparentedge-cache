<?php
/**
 * Redis management block for admin configuration
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Block\Adminhtml\System\Config;

use TransparentEdge\CDN\Model\Redis\RedisManager;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

class RedisControl extends Field
{
    protected $_template = 'TransparentEdge_CDN::system/config/redis_control.phtml';

    private RedisManager $redisManager;

    public function __construct(
        Context      $context,
        RedisManager $redisManager,
        array        $data = []
    ) {
        $this->redisManager = $redisManager;
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    public function getManageUrl(): string
    {
        return $this->getUrl('transparentedge/redis/manage');
    }

    public function getRedisStatus(): array
    {
        return $this->redisManager->getStatus();
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }
}
