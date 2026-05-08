<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SpeculationInjection implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'php', 'label' => __('PHP (origin) — compatible with all setups')],
            ['value' => 'vcl', 'label' => __('VCL (edge) — recommended, requires VCL deployment')],
        ];
    }
}
