<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Checkout;

use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\Checkout\StripeInChat;
use Idea89\Assistant\Model\Checkout\StripeModule;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StripeInChatTest extends TestCase
{
    /** @var CheckoutConfig&MockObject */
    private $checkoutConfig;

    /** @var StripeModule&MockObject */
    private $stripe;

    protected function setUp(): void
    {
        $this->checkoutConfig = $this->createMock(CheckoutConfig::class);
        $this->stripe = $this->createMock(StripeModule::class);
    }

    /**
     * Everything the flow needs, with one thing overridden per test.
     *
     * @param array<string, mixed> $over
     */
    private function given(array $over = []): StripeInChat
    {
        $v = $over + [
            'enabled'   => true,
            'installed' => true,
            'version'   => '4.7.2',
            'active'    => true,
            'action'    => 'authorize_capture',
            'manual'    => ['card', 'link'],
            'pk'        => 'pk_test_123',
        ];
        $this->checkoutConfig->method('isStripeInChatEnabled')->willReturn($v['enabled']);
        $this->stripe->method('isInstalled')->willReturn($v['installed']);
        $this->stripe->method('version')->willReturn($v['version']);
        $this->stripe->method('isEnabled')->willReturn($v['active']);
        $this->stripe->method('paymentAction')->willReturn($v['action']);
        $this->stripe->method('manualAuthenticationTypes')->willReturn($v['manual']);
        $this->stripe->method('publishableKey')->willReturn($v['pk']);
        return new StripeInChat($this->checkoutConfig, $this->stripe);
    }

    public function testAvailableWhenEverythingTheFlowNeedsIsInPlace(): void
    {
        $this->assertTrue($this->given()->isAvailable());
    }

    public function testNotAvailableUntilTheMerchantTurnsItOn(): void
    {
        $this->assertFalse($this->given(['enabled' => false])->isAvailable());
    }

    public function testNotAvailableWithoutTheStripeModule(): void
    {
        $this->assertFalse($this->given(['installed' => false])->isAvailable());
    }

    public function testNotAvailableOnAStripeModuleOutsideTheSupportedReleases(): void
    {
        $this->assertFalse($this->given(['version' => '4.3.9'])->isAvailable());
    }

    public function testNotAvailableOnTheNextMajorStripeModule(): void
    {
        $this->assertFalse($this->given(['version' => '5.0.0'])->isAvailable());
    }

    public function testNotAvailableWhenTheStripeModuleIsInactive(): void
    {
        $this->assertFalse($this->given(['active' => false])->isAvailable());
    }

    public function testNotAvailableWhenThePaymentActionOnlySavesTheCard(): void
    {
        $this->assertFalse($this->given(['action' => 'order'])->isAvailable());
    }

    public function testNotAvailableWhenCardsAreNotAuthenticatedBeforeTheOrderExists(): void
    {
        // Without 'card' here the module would place a 3-D Secure order
        // unpaid instead of stopping first.
        $this->assertFalse($this->given(['manual' => ['link']])->isAvailable());
    }

    public function testNotAvailableWithoutAPublishableKey(): void
    {
        $this->assertFalse($this->given(['pk' => ''])->isAvailable());
    }

    public function testAStripeModuleThatThrowsMakesItUnavailableRatherThanBreakingCheckout(): void
    {
        $this->checkoutConfig->method('isStripeInChatEnabled')->willReturn(true);
        $this->stripe->method('isInstalled')->willReturn(true);
        $this->stripe->method('version')->willReturn('4.7.2');
        $this->stripe->method('isEnabled')->willThrowException(new \RuntimeException('Invalid API key'));

        $this->assertFalse((new StripeInChat($this->checkoutConfig, $this->stripe))->isAvailable());
    }

    public function testTheAnswerIsWorkedOutOncePerRequest(): void
    {
        $this->checkoutConfig->expects($this->once())->method('isStripeInChatEnabled')->willReturn(false);
        $subject = new StripeInChat($this->checkoutConfig, $this->stripe);

        $subject->isAvailable();
        $subject->isAvailable();
    }

    public function testClientConfigIsWhatStripeJsNeedsForThisBasket(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getData')->willReturnMap([
            ['quote_currency_code', null, 'GBP'],
            ['grand_total', null, 83.0],
        ]);
        $this->stripe->method('publishableKey')->willReturn('pk_test_123');
        $this->stripe->method('mode')->willReturn('test');
        $this->stripe->method('locale')->willReturn('en-GB');
        $this->stripe->method('toStripeAmount')->with(83.0, 'GBP')->willReturn(8300);
        $this->stripe->method('paymentMethodConfiguration')->willReturn('pmc_123');

        $config = (new StripeInChat($this->checkoutConfig, $this->stripe))->clientConfig($quote);

        $this->assertSame([
            'publishable_key' => 'pk_test_123',
            'mode'            => 'test',
            'locale'          => 'en-GB',
            'currency'        => 'gbp',
            'amount'          => 8300,
            'payment_method_configuration' => 'pmc_123',
        ], $config);
    }
}
