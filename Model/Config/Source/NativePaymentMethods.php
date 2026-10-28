<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Payment\Model\Config as PaymentConfig;

/**
 * Multiselect source for the `idea89/checkout/native_methods` allowlist.
 *
 * Lists every payment method active in Magento (the same set a shopper could
 * reach on the real checkout page) and flags, in the label itself, which ones
 * the assistant can complete without leaving the conversation: Magento's
 * offline methods. That single word of copy ("works in chat" / "leaves the
 * chat to pay") does more to stop a merchant misconfiguring this than any
 * amount of comment text. Ticking anything else does nothing: Facade only
 * ever offers offline methods.
 *
 * This list is NOT the allowlist itself — it only decides which options are
 * offered. The stored value (what the merchant actually ticked) is read back
 * by CheckoutConfig::getNativeMethods(), and Model\Checkout\Facade is the
 * only place that value is ever trusted against a live request.
 */
class NativePaymentMethods implements OptionSourceInterface
{
    public function __construct(
        private readonly PaymentConfig $paymentConfig
    ) {}

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->paymentConfig->getActiveMethods() as $code => $method) {
            $title = (string) $method->getTitle();
            if ($title === '') {
                $title = $code;
            }
            // Same test Model\Checkout\Facade applies: only offline methods
            // complete in the conversation, whatever the merchant ticks.
            $suffix = $method->isOffline()
                ? __('works in chat')
                : __('leaves the chat to pay');
            $options[] = [
                'value' => $code,
                'label' => __('%1 - %2', $title, $suffix),
            ];
        }
        return $options;
    }
}
