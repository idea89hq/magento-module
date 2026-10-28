<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Checkout;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\ObjectManagerInterface;

/**
 * The few things the in-chat checkout reads from the merchant's own Stripe
 * module (stripe/module-payments, StripeIntegration_Payments).
 *
 * That module is optional: most stores do not have it. A constructor
 * dependency on its classes would break setup:di:compile on every store
 * without it, so they are fetched from the ObjectManager here, and only
 * after Module\Manager says the module is enabled. Nothing else in this
 * module touches a StripeIntegration class.
 */
class StripeModule
{
    public const MODULE = 'StripeIntegration_Payments';

    // ::class only builds the name; nothing is loaded until isInstalled().
    private const CONFIG = \StripeIntegration\Payments\Model\Config::class;
    private const LOCALE = \StripeIntegration\Payments\Helper\Locale::class;
    private const GENERIC = \StripeIntegration\Payments\Helper\Generic::class;

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly PackageInfo $packageInfo,
        private readonly ObjectManagerInterface $objectManager
    ) {}

    public function isInstalled(): bool
    {
        return $this->moduleManager->isEnabled(self::MODULE) && class_exists(self::CONFIG);
    }

    /** The Composer version of stripe/module-payments, '' when unknown. */
    public function version(): string
    {
        return (string) $this->packageInfo->getVersion(self::MODULE);
    }

    /** Stripe active for this store, with keys that work. */
    public function isEnabled(): bool
    {
        return (bool) $this->config()->isEnabled();
    }

    /** 'authorize', 'authorize_capture' or 'order' (save the card, charge later). */
    public function paymentAction(): string
    {
        return (string) $this->config()->getPaymentAction();
    }

    /**
     * Payment method types for which the module stops the order with
     * "Authentication Required: <client_secret>" instead of placing it
     * unpaid, so the browser can run 3-D Secure and place it again.
     *
     * @return string[]
     */
    public function manualAuthenticationTypes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            (array) $this->config()->getManualAuthenticationPaymentMethods()
        )));
    }

    public function publishableKey(): string
    {
        return (string) $this->config()->getPublishableKey();
    }

    /** 'test' or 'live'. */
    public function mode(): string
    {
        return (string) $this->config()->getStripeMode();
    }

    /**
     * The Stripe payment method configuration the module's PaymentIntents
     * use (pmc_…), or null for Stripe's automatic payment methods. Stripe
     * refuses a payment whose fields were set up differently, so the chat's
     * fields use exactly this.
     */
    public function paymentMethodConfiguration(): ?string
    {
        $pmc = $this->config()->getPaymentMethodConfiguration();
        return is_string($pmc) && $pmc !== '' ? $pmc : null;
    }

    public function locale(): string
    {
        return (string) $this->objectManager->get(self::LOCALE)->getStripeJsLocale();
    }

    /** The module's own conversion, so zero-decimal currencies match its PaymentIntent. */
    public function toStripeAmount(float $amount, string $currency): int
    {
        return (int) $this->objectManager->get(self::GENERIC)->convertMagentoAmountToStripeAmount($amount, $currency);
    }

    /**
     * @return object StripeIntegration\Payments\Model\Config
     */
    private function config(): object
    {
        return $this->objectManager->get(self::CONFIG);
    }
}
