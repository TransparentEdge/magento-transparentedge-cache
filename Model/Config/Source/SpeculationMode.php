<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SpeculationMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'conservative', 'label' => __('Conservative (prefetch on click)')],
            ['value' => 'balanced',     'label' => __('Balanced (prefetch on hover) — recommended')],
            ['value' => 'aggressive',   'label' => __('Aggressive (prefetch on viewport + prerender on click)')],
        ];
    }
}
