<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Payment\Model\Config as PaymentConfig;

/**
 * POST admin/idea89/ajax/testcheckoutpanel
 *
 * A pre-flight check a merchant can run BEFORE switching checkout mode to
 * "embedded" ("Checkout in chat") — it surfaces known conflicts (a
 * layout-replacing checkout extension, a payment method that redirects
 * off-site, guest checkout being off, reCAPTCHA on checkout) up front,
 * instead of a shopper discovering them live via the panel's own 6-second
 * handshake-timeout fallback.
 *
 * Checks run in a fixed order below. Most append at most one message; the two
 * list-driven checks (one-step-checkout extensions, redirect payment methods)
 * can append one message per matching item. The verdict is the worst level
 * seen across every message: any `error` makes it `blocked`; otherwise any
 * `warn` makes it `warn`; otherwise `ok`.
 */
class TestCheckoutPanel extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Idea89_Assistant::config';

    /** Layout-replacing one-step-checkout extensions: module name => merchant-facing label. */
    private const OSC_EXTENSIONS = [
        'Amasty_Checkout' => 'Amasty One Step Checkout',
        'Mageplaza_Osc' => 'Mageplaza One Step Checkout',
        'Iwd_Opc' => 'IWD One Page Checkout',
        'Mirasvit_OSC' => 'Mirasvit One Step Checkout',
        'MageDelight_OneStepCheckout' => 'MageDelight One Step Checkout',
    ];

    /** Payment methods that redirect the shopper off-site to pay: code => merchant-facing label. */
    private const REDIRECT_PAYMENT_METHODS = [
        'paypal_express' => 'PayPal Express Checkout',
        'braintree_paypal' => 'Braintree PayPal',
        'klarna_kp' => 'Klarna',
        'adyen_hpp' => 'Adyen Hosted Payment Pages',
        'amazon_payment_v2' => 'Amazon Pay',
    ];

    private const XML_GUEST_CHECKOUT = 'checkout/options/guest_checkout';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ModuleManager $moduleManager,
        private readonly PaymentConfig $paymentConfig,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $messages = $this->buildMessages();
        return $result->setData(['verdict' => $this->computeVerdict($messages), 'messages' => $messages]);
    }

    /**
     * @return array<int, array{level: string, text: string}>
     */
    private function buildMessages(): array
    {
        $messages = [];

        if ($this->moduleManager->isEnabled('Hyva_Checkout')) {
            $messages[] = ['level' => 'error', 'text' =>
                'Hyvä Checkout replaces the standard checkout layout, so it cannot render inside '
                . 'the assistant panel. Shoppers will get the Express handoff instead.'];
        }

        foreach (self::OSC_EXTENSIONS as $module => $label) {
            if ($this->moduleManager->isEnabled($module)) {
                $messages[] = ['level' => 'warn', 'text' =>
                    $label . ' replaces the checkout layout. The panel may still work, but test '
                    . 'it on the storefront before going live.'];
            }
        }

        if (!$this->moduleManager->isEnabled('Magento_Checkout')) {
            $messages[] = ['level' => 'error', 'text' =>
                'Magento_Checkout is disabled; there is no checkout to render.'];
        }

        $activeMethods = array_keys($this->paymentConfig->getActiveMethods());
        foreach (self::REDIRECT_PAYMENT_METHODS as $code => $label) {
            if (in_array($code, $activeMethods, true)) {
                $messages[] = ['level' => 'warn', 'text' =>
                    $label . ' sends shoppers to its own site to pay. The panel offers an '
                    . "'Open full checkout' link for these; the in-panel flow may end early."];
            }
        }

        if (!$this->scopeConfig->isSetFlag(self::XML_GUEST_CHECKOUT)) {
            $messages[] = ['level' => 'warn', 'text' =>
                'Guest checkout is off, so only signed-in shoppers will see the panel. Everyone '
                . 'else gets the Express handoff.'];
        }

        // Not in the original checklist — added because Magento_ReCaptchaCheckout is
        // a real, verified-enabled module on the target store and a plausible
        // framed-checkout failure mode: reCAPTCHA challenges can behave oddly
        // inside a same-origin iframe depending on the widget type in use.
        if ($this->moduleManager->isEnabled('Magento_ReCaptchaCheckout')) {
            $messages[] = ['level' => 'warn', 'text' =>
                'reCAPTCHA is active on your checkout. It generally works inside the assistant '
                . 'panel, but test a real order before going live.'];
        }

        if (empty($messages)) {
            $messages[] = ['level' => 'ok', 'text' => 'No known conflicts. Test the panel on your storefront to confirm.'];
        }

        return $messages;
    }

    /**
     * @param array<int, array{level: string, text: string}> $messages
     */
    private function computeVerdict(array $messages): string
    {
        $hasWarn = false;
        foreach ($messages as $message) {
            if ($message['level'] === 'error') {
                return 'blocked';
            }
            if ($message['level'] === 'warn') {
                $hasWarn = true;
            }
        }
        return $hasWarn ? 'warn' : 'ok';
    }
}
