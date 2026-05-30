<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Dropdown source for the `idea89/locator/layout` config field.
 * Empty value (leave blank) defers to the global dashboard setting —
 * the override rule documented in Block/Locator::getStorefinderLayout().
 */
class StorefinderLayout implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('Use dashboard setting')],
            ['value' => 'fullwidth', 'label' => __('Fullwidth (edge-to-edge map)')],
            ['value' => 'boxed', 'label' => __('Boxed (max-width rounded card)')],
        ];
    }
}
