<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Block\Checkout;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Checkout\Model\Session as CheckoutSession;
use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\Config;

/**
 * Supplies the postMessage bridge that lets the framed checkout talk to the
 * chat widget. Rendered on two handles:
 *
 *   idea89_checkout_mini      → emits `ready` and `resize`
 *   checkout_onepage_success  → emits `success` with the order increment id
 *
 * The same block serves both; which message it emits is decided by the
 * `bridge_mode` layout argument declared on each handle — NOT by inferring it
 * from session state. CheckoutSession::getLastRealOrder() reflects the LAST
 * order placed in this browser session; for a repeat purchase that value is
 * still set when the shopper opens a brand new mini-checkout, so it cannot
 * tell the two pages apart on its own. See getBridgeMode().
 *
 * Nothing here runs unless the shopper is actually inside a frame — the
 * template checks window.parent !== window — so adding the block to the
 * success page does not affect ordinary checkout traffic.
 */
class Bridge extends Template
{
    protected $_template = 'Idea89_Assistant::checkout/bridge.phtml';

    private const MODE_HANDSHAKE = 'handshake';
    private const MODE_SUCCESS = 'success';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly CheckoutConfig $checkoutConfig,
        private readonly CheckoutSession $checkoutSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function shouldRender(): bool
    {
        return $this->config->isEnabled() && $this->checkoutConfig->isEmbedded();
    }

    /**
     * Which of the two pages this render is on, as declared explicitly by the
     * layout XML (`bridge_mode` block argument) rather than guessed from
     * session state. A missing or unrecognised value collapses to
     * `handshake`: the safe failure mode is "no success message", never a
     * false positive that tells the widget an order was placed when it
     * wasn't.
     */
    public function getBridgeMode(): string
    {
        $mode = (string) $this->getData('bridge_mode');
        return $mode === self::MODE_SUCCESS ? self::MODE_SUCCESS : self::MODE_HANDSHAKE;
    }

    /**
     * JSON for the `success` message, or null unless this render is
     * genuinely the success page (bridge_mode=success from the layout) AND a
     * real order is present in the session. The mode check is what makes
     * this safe against a stale last-real-order-id from an earlier purchase
     * in the same session; the order-presence check is belt and braces on
     * top of that, so the success page itself still emits nothing if it
     * somehow renders without a completed order.
     *
     * Grand total is formatted to two decimals as a STRING: the widget only
     * displays it and forwards it for attribution, and a float would pick up
     * JSON float formatting noise on the way through.
     */
    public function getSuccessPayloadJson(): ?string
    {
        if ($this->getBridgeMode() !== self::MODE_SUCCESS) {
            return null;
        }

        // getLastRealOrder() never returns null (Session.php always creates
        // an Order instance); with no placed order it returns one that is
        // simply unloaded, so getIncrementId() is null on it.
        $order = $this->checkoutSession->getLastRealOrder();
        $incrementId = (string) $order->getIncrementId();
        if ($incrementId === '') {
            return null;
        }

        return json_encode(
            [
                'source'   => 'idea89-checkout',
                'type'     => 'success',
                'orderId'  => $incrementId,
                'total'    => number_format((float) $order->getGrandTotal(), 2, '.', ''),
                'currency' => (string) $order->getOrderCurrencyCode(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
