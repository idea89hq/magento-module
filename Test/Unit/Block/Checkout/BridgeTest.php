<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Block\Checkout;

use Idea89\Assistant\Block\Checkout\Bridge;
use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class BridgeTest extends TestCase
{
    /**
     * @param Order|null $order Defaults to an UNLOADED order (getIncrementId()
     *     null) — that is what CheckoutSession::getLastRealOrder() actually
     *     returns when no order has been placed; it never returns null itself
     *     (Model/Session.php always constructs an Order instance).
     * @param string|null $bridgeMode Layout `bridge_mode` argument. Passing
     *     null omits the data key entirely, to exercise the "never set"
     *     default path distinctly from an explicit value.
     */
    private function build(
        bool $enabled,
        bool $embedded,
        ?Order $order = null,
        ?string $bridgeMode = 'handshake'
    ): Bridge {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        $checkoutConfig = $this->createMock(CheckoutConfig::class);
        $checkoutConfig->method('isEmbedded')->willReturn($embedded);

        if ($order === null) {
            $order = $this->createMock(Order::class);
            $order->method('getIncrementId')->willReturn(null);
        }

        $session = $this->createMock(CheckoutSession::class);
        $session->method('getLastRealOrder')->willReturn($order);

        $data = [];
        if ($bridgeMode !== null) {
            $data['bridge_mode'] = $bridgeMode;
        }

        // Template::__construct pulls many collaborators off Context; a bare
        // mock of Context can fatal. The ObjectManager test helper builds a
        // real-enough Context (and everything else Template needs) and lets
        // us inject just the constructor args we care about, by name.
        $objectManager = new ObjectManager($this);
        return $objectManager->getObject(Bridge::class, [
            'config' => $config,
            'checkoutConfig' => $checkoutConfig,
            'checkoutSession' => $session,
            'data' => $data,
        ]);
    }

    private function orderWith(string $incrementId, float $total, string $currency): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getGrandTotal')->willReturn($total);
        $order->method('getOrderCurrencyCode')->willReturn($currency);
        return $order;
    }

    public function testDoesNotRenderWhenTheModeIsNotEmbedded(): void
    {
        $this->assertFalse($this->build(true, false)->shouldRender());
    }

    public function testDoesNotRenderWhenTheModuleIsDisabled(): void
    {
        $this->assertFalse($this->build(false, true)->shouldRender());
    }

    public function testRendersWhenEmbedded(): void
    {
        $this->assertTrue($this->build(true, true)->shouldRender());
    }

    /**
     * THE regression test for the fix-round-1 finding. last_real_order_id is
     * cleared only by Onepage::saveOrder() on the NEXT order's submission —
     * not by initCheckout(), which is all the mini-checkout controller calls.
     * So a shopper who has already placed one order this session, and opens
     * the mini-checkout again for a second purchase, still has a "last real
     * order" in the session while looking at a page that is NOT a success
     * page. bridge_mode is what must stop that from being reported as a
     * success. This test fails against the pre-fix Bridge (which inferred
     * success purely from order presence) and passes against the fix.
     */
    public function testHandshakeModeNeverEmitsSuccessEvenWithARealOrderPresent(): void
    {
        $staleOrderFromAnEarlierPurchase = $this->orderWith('000000123', 50.0, 'GBP');
        $bridge = $this->build(true, true, $staleOrderFromAnEarlierPurchase, 'handshake');
        $this->assertNull($bridge->getSuccessPayloadJson());
    }

    public function testSuccessPayloadCarriesTheIncrementIdTotalAndCurrency(): void
    {
        $order = $this->orderWith('000000123', 142.5, 'GBP');
        $decoded = json_decode(
            (string) $this->build(true, true, $order, 'success')->getSuccessPayloadJson(),
            true
        );
        $this->assertSame('idea89-checkout', $decoded['source']);
        $this->assertSame('success', $decoded['type']);
        $this->assertSame('000000123', $decoded['orderId']);
        $this->assertSame('142.50', $decoded['total']);
        $this->assertSame('GBP', $decoded['currency']);
    }

    public function testSuccessPayloadIsNullInSuccessModeWithoutARealOrder(): void
    {
        // build()'s default order is the unloaded shape getLastRealOrder()
        // actually returns with nothing placed — getIncrementId() === null.
        $this->assertNull($this->build(true, true, null, 'success')->getSuccessPayloadJson());
    }

    public function testMissingBridgeModeDefaultsToHandshakeAndSuppressesSuccess(): void
    {
        $order = $this->orderWith('000000123', 50.0, 'GBP');
        $bridge = $this->build(true, true, $order, null);
        $this->assertSame('handshake', $bridge->getBridgeMode());
        $this->assertNull($bridge->getSuccessPayloadJson());
    }

    public function testUnknownBridgeModeDefaultsToHandshakeAndSuppressesSuccess(): void
    {
        $order = $this->orderWith('000000123', 50.0, 'GBP');
        $bridge = $this->build(true, true, $order, 'some-unrecognised-value');
        $this->assertSame('handshake', $bridge->getBridgeMode());
        $this->assertNull($bridge->getSuccessPayloadJson());
    }
}
