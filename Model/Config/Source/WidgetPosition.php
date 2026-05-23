<?php

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class WidgetPosition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'bottom-right', 'label' => __('Bottom Right')],
            ['value' => 'bottom-left',  'label' => __('Bottom Left')],
        ];
    }
}
