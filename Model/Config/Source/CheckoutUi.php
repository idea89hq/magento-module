<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How native checkout presents itself inside the assistant.
 *
 * The value lives in the merchant's IDEA89 account, not here, so that the
 * dashboard and this admin screen can never show different answers for the
 * same setting. This class only names the two choices.
 */
class CheckoutUi implements OptionSourceInterface
{
    public const MODE_FULL   = 'full';
    public const MODE_INLINE = 'inline';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::MODE_FULL,
                'label' => __('Full window'),
            ],
            [
                'value' => self::MODE_INLINE,
                'label' => __('In the chat'),
            ],
        ];
    }
}
