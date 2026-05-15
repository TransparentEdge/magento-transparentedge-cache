<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LogLevel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'error',   'label' => __('Error — only errors')],
            ['value' => 'warning', 'label' => __('Warning — errors + warnings')],
            ['value' => 'info',    'label' => __('Info — standard (recommended)')],
            ['value' => 'debug',   'label' => __('Debug — verbose (development only)')],
        ];
    }
}
