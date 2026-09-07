<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Model\Session as CustomerSession;
use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\Config;

/**
 * GET /idea89/checkout/mini
 *
 * Renders the merchant's REAL checkout — the same checkout_index_index layout,
 * the same payment and shipping components, the same third-party extensions —
 * with the site chrome stripped by the idea89_checkout_mini handle. The chat
 * widget frames this same-origin, so the shopper keeps their session, their
 * quote, and their payment provider's own iframes.
 *
 * IDEA89 renders nothing payment-related and never receives card data. The
 * merchant's PCI posture is unchanged from a normal checkout visit.
 *
 * Magento derives the layout handle from the full action name, so the route
 * idea89/checkout/mini yields the `idea89_checkout_mini` handle for free —
 * unlike Controller/Product/Mini.php, which has to synthesise its handles
 * because the catalog helper keys them off the wrong action name.
 *
 * The guards below mirror Magento\Checkout\Controller\Index\Index::execute()
 * — including its canOnepageCheckout() check, which is core's first gate.
 * Divergences, both deliberate:
 *   - We do NOT call regenerateId(). Core regenerates on the cart → checkout
 *     transition as session-fixation defence; here the shopper is already deep
 *     in a session on this origin, and rotating the cookie mid-flight races
 *     with the widget's other in-flight same-origin fetches.
 *   - Failures render a tiny HTML page that postMessages a machine-readable
 *     code to the parent rather than redirecting, because a redirect inside
 *     the frame is invisible to the widget.
 *
 * Note: guest-checkout eligibility is NOT a Quote method. Quote::getHasError()
 * only exists via DataObject's __call() magic getter (reads the "has_error"
 * data key) — real, but not annotated, so a PHPUnit double can't stub it
 * directly. isAllowedGuestCheckout() isn't on Quote at all; core's own
 * checkout/index/index controller calls it as
 * Magento\Checkout\Helper\Data::isAllowedGuestCheckout($quote). Calling
 * $quote->isAllowedGuestCheckout() directly — which an earlier draft of this
 * controller did — throws "Invalid method" at runtime, because that method
 * name doesn't match any of DataObject::__call()'s get/set/uns/has prefixes.
 */
class Mini implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly CheckoutConfig $checkoutConfig,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly Onepage $onepage,
        private readonly CheckoutHelper $checkoutHelper
    ) {}

    public function execute(): ResultInterface
    {
        // 404 rather than 403 when the feature is off: an unused route should
        // not advertise its own existence.
        if (!$this->config->isEnabled() || !$this->checkoutConfig->isEmbedded()) {
            return $this->bridgeError(404, 'not_available');
        }

        // Core's FIRST guard (Checkout\Controller\Index\Index:24). A merchant
        // can switch one-page checkout off in admin; if they have, we must not
        // render one in a frame either. Honouring the merchant's own settings
        // is the whole premise of this tier.
        if (!$this->checkoutHelper->canOnepageCheckout()) {
            return $this->bridgeError(403, 'onepage_checkout_disabled');
        }

        $quote = $this->checkoutSession->getQuote();

        if (!$quote->hasItems() || $quote->getHasError() || !$quote->validateMinimumAmount()) {
            return $this->bridgeError(409, 'empty_cart');
        }

        if (!$this->customerSession->isLoggedIn()
            && !$this->checkoutHelper->isAllowedGuestCheckout($quote)
        ) {
            return $this->bridgeError(403, 'guest_checkout_disabled');
        }

        $this->checkoutSession->setCartWasUpdated(false);
        // Drops multi-shipping state and its addresses, exactly as core does
        // before rendering the one-page checkout.
        $this->onepage->initCheckout();

        $page = $this->pageFactory->create();
        $page->getConfig()->setPageLayout('1column');
        $page->getConfig()->getTitle()->set(__('Checkout'));
        $page->setHeader('Cache-Control', 'no-store', true);
        // Framed by our own storefront only. Magento defaults to SAMEORIGIN;
        // setting it explicitly means a merchant who has loosened the global
        // header for some other reason does not loosen this page with it.
        $page->setHeader('X-Frame-Options', 'SAMEORIGIN', true);

        return $page;
    }

    /**
     * A minimal same-origin page whose only job is to tell the framing widget
     * why the checkout will not render, so it can fall back immediately
     * instead of waiting out the handshake timeout.
     */
    private function bridgeError(int $status, string $code): Raw
    {
        $json = json_encode(
            ['source' => 'idea89-checkout', 'type' => 'error', 'code' => $code],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        /** @var Raw $raw */
        $raw = $this->rawFactory->create();
        return $raw->setHttpResponseCode($status)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setHeader('X-Frame-Options', 'SAMEORIGIN', true)
            ->setContents(
                '<!doctype html><meta charset="utf-8"><title></title>'
                . '<script>if(window.parent!==window){'
                . 'window.parent.postMessage(' . $json . ',window.location.origin);}</script>'
            );
    }
}
