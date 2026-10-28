<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Checkout;

use Idea89\Assistant\Model\CheckoutConfig;
use Magento\Quote\Model\Quote;

/**
 * Whether the in-chat checkout may take a card or wallet payment through the
 * merchant's Stripe module, and what the widget needs to show Stripe's
 * fields.
 *
 * Every condition is one the flow depends on, not a preference:
 *   - the merchant turned it on (it takes real money);
 *   - the Stripe module is installed, enabled, with working keys, and is a
 *     4.x release from 4.4: the flow relies on its "Authentication Required"
 *     stop and on its reuse of a confirmed PaymentIntent when the order is
 *     placed again, which are not public API;
 *   - the payment action takes a payment ('order' only saves the card);
 *   - cards are authenticated manually, so a card that needs 3-D Secure
 *     stops before the order exists instead of leaving an unpaid order.
 * If any of them fails, the chat offers what it offered before and the
 * shopper pays on the checkout page.
 */
class StripeInChat
{
    public const METHOD = 'stripe_payments';

    private const MIN_VERSION = '4.4.0';
    private const MAX_VERSION = '5.0.0';

    private ?bool $available = null;

    public function __construct(
        private readonly CheckoutConfig $checkoutConfig,
        private readonly StripeModule $stripe
    ) {}

    /** Worked out once per request: every checkout call asks. */
    public function isAvailable(): bool
    {
        return $this->available ??= $this->check();
    }

    private function check(): bool
    {
        if (!$this->checkoutConfig->isStripeInChatEnabled() || !$this->stripe->isInstalled()) {
            return false;
        }
        $version = $this->stripe->version();
        if ($version === ''
            || version_compare($version, self::MIN_VERSION, '<')
            || version_compare($version, self::MAX_VERSION, '>=')) {
            return false;
        }
        try {
            return $this->stripe->isEnabled()
                && in_array($this->stripe->paymentAction(), ['authorize', 'authorize_capture'], true)
                && in_array('card', $this->stripe->manualAuthenticationTypes(), true)
                && $this->stripe->publishableKey() !== '';
        } catch (\Throwable $e) {
            // A misconfigured Stripe module must not break the chat checkout.
            return false;
        }
    }

    /**
     * What the widget needs to show Stripe's fields for this basket. Amount
     * in Stripe's minor units, from the module's own conversion, so the
     * fields and the PaymentIntent the module creates agree.
     *
     * @return array{publishable_key: string, mode: string, locale: string, currency: string, amount: int, payment_method_configuration: ?string}
     */
    public function clientConfig(Quote $quote): array
    {
        return [
            'publishable_key' => $this->stripe->publishableKey(),
            'mode'            => $this->stripe->mode() === 'live' ? 'live' : 'test',
            'locale'          => $this->stripe->locale(),
            'currency'        => strtolower((string) $quote->getData('quote_currency_code')),
            'amount'          => $this->amount($quote),
            // The module's own choice, never a list of ours (see StripeModule).
            'payment_method_configuration' => $this->stripe->paymentMethodConfiguration(),
        ];
    }

    /** The basket total in Stripe's minor units. */
    public function amount(Quote $quote): int
    {
        return $this->stripe->toStripeAmount(
            (float) $quote->getData('grand_total'),
            (string) $quote->getData('quote_currency_code')
        );
    }
}
