<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Idea89\Assistant\Model\CheckoutConfig;

/**
 * The four rungs of the in-widget checkout ladder. Mutually exclusive by
 * design — ACP is a separate Yes/No field because it serves agents outside
 * the widget and can run alongside any rung.
 */
class CheckoutMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => CheckoutConfig::MODE_OFF,
                'label' => __('Off — send shoppers to the cart page'),
            ],
            [
                'value' => CheckoutConfig::MODE_EXPRESS,
                'label' => __('Express handoff — confirm the basket in chat, then go straight to checkout (default)'),
            ],
            [
                'value' => CheckoutConfig::MODE_EMBEDDED,
                'label' => __('Checkout in chat — your own Magento checkout inside the assistant panel'),
            ],
            [
                'value' => CheckoutConfig::MODE_NATIVE,
                'label' => __('Native checkout (beta) — the assistant collects details and places the order'),
            ],
        ];
    }
}
