<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Controller\Checkout;

use Idea89\Assistant\Controller\Checkout\Mini;
use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\Config;
use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\Result\Page as ResultPage;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

class MiniTest extends TestCase
{
    private function build(
        bool $moduleEnabled,
        string $mode,
        bool $hasItems,
        bool $guestAllowed,
        bool $loggedIn,
        bool $onepageEnabled = true
    ): array {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn($moduleEnabled);

        $checkoutConfig = $this->createMock(CheckoutConfig::class);
        $checkoutConfig->method('isEmbedded')->willReturn($mode === CheckoutConfig::MODE_EMBEDDED);

        // Quote::getHasError() and Quote::isAllowedGuestCheckout() are not real
        // methods — getHasError() only exists via DataObject::__call() magic
        // (unconfigurable on a PHPUnit double with no @method annotation), and
        // isAllowedGuestCheckout() isn't on Quote at all: it's
        // Magento\Checkout\Helper\Data::isAllowedGuestCheckout(Quote $quote),
        // exactly as core's own checkout/index/index controller calls it. We
        // leave getHasError() unstubbed — the mock's default (unconfigured)
        // getData() stub returns null, so the magic getter reads as falsy,
        // which is what every fixture here needs.
        $quote = $this->createMock(Quote::class);
        $quote->method('hasItems')->willReturn($hasItems);
        $quote->method('validateMinimumAmount')->willReturn(true);

        $checkoutHelper = $this->createMock(CheckoutHelper::class);
        $checkoutHelper->method('isAllowedGuestCheckout')->willReturn($guestAllowed);
        $checkoutHelper->method('canOnepageCheckout')->willReturn($onepageEnabled);

        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willReturn($quote);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn($loggedIn);

        $pageConfig = $this->createMock(PageConfig::class);
        $title = $this->createMock(\Magento\Framework\View\Page\Title::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createMock(ResultPage::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->method('setHeader')->willReturnSelf();
        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        // Captured rather than asserted-on-the-mock directly: bridgeError()
        // sets several headers in one chained call, so a plain
        // ->expects()->with('X-Frame-Options', ...) would also have to match
        // (and therefore break on) the Content-Type and Cache-Control calls.
        // Recording every (name => value) actually sent lets a test assert on
        // just the one header it cares about.
        $rawHeaders = [];
        $raw = $this->createMock(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnSelf();
        $raw->method('setHeader')->willReturnCallback(
            function (string $name, string $value) use ($raw, &$rawHeaders) {
                $rawHeaders[$name] = $value;
                return $raw;
            }
        );
        $raw->method('setContents')->willReturnSelf();
        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $onepage = $this->createMock(Onepage::class);

        $controller = new Mini(
            $pageFactory,
            $rawFactory,
            $config,
            $checkoutConfig,
            $checkoutSession,
            $customerSession,
            $onepage,
            $checkoutHelper
        );

        return ['controller' => $controller, 'page' => $page, 'raw' => $raw, 'rawHeaders' => &$rawHeaders];
    }

    public function testReturnsNotFoundWhenTheModuleIsDisabled(): void
    {
        $b = $this->build(false, CheckoutConfig::MODE_EMBEDDED, true, true, false);
        $b['raw']->expects($this->once())->method('setHttpResponseCode')->with(404)->willReturnSelf();
        $b['controller']->execute();
    }

    public function testReturnsNotFoundWhenTheModeIsNotEmbedded(): void
    {
        // A store on express must not expose a framable checkout URL at all.
        $b = $this->build(true, CheckoutConfig::MODE_EXPRESS, true, true, false);
        $b['raw']->expects($this->once())->method('setHttpResponseCode')->with(404)->willReturnSelf();
        $b['controller']->execute();
    }

    public function testReturnsForbiddenWhenOnePageCheckoutIsDisabled(): void
    {
        // Mirrors core's checkout/index/index FIRST guard
        // (Checkout\Controller\Index\Index::execute():24): a merchant who has
        // switched one-page checkout off in admin must not get a framed
        // checkout either, even with items in the quote.
        $b = $this->build(true, CheckoutConfig::MODE_EMBEDDED, true, true, false, false);
        $b['raw']->expects($this->once())->method('setHttpResponseCode')->with(403)->willReturnSelf();
        $b['controller']->execute();
    }

    public function testReturnsConflictOnAnEmptyQuote(): void
    {
        $b = $this->build(true, CheckoutConfig::MODE_EMBEDDED, false, true, false);
        $b['raw']->expects($this->once())->method('setHttpResponseCode')->with(409)->willReturnSelf();
        $b['controller']->execute();
    }

    public function testReturnsForbiddenWhenGuestCheckoutIsOffAndNobodyIsLoggedIn(): void
    {
        $b = $this->build(true, CheckoutConfig::MODE_EMBEDDED, true, false, false);
        $b['raw']->expects($this->once())->method('setHttpResponseCode')->with(403)->willReturnSelf();
        $b['controller']->execute();
    }

    public function testRendersThePageForALoggedInCustomerEvenWhenGuestCheckoutIsOff(): void
    {
        $b = $this->build(true, CheckoutConfig::MODE_EMBEDDED, true, false, true);
        $result = $b['controller']->execute();
        $this->assertInstanceOf(ResultPage::class, $result);
    }

    public function testSetsNoStoreAndSameOriginFramingHeaders(): void
    {
        $b = $this->build(true, CheckoutConfig::MODE_EMBEDDED, true, true, false);
        $b['page']->expects($this->exactly(2))->method('setHeader')->willReturnSelf();
        $b['controller']->execute();
    }

    public function testBridgeErrorSetsXFrameOptionsSameOrigin(): void
    {
        // Any guard failure goes through bridgeError() — an empty quote is
        // just a convenient one to trigger here. Guards against a regression
        // on the framing header specifically (curl proved it live, but that
        // doesn't run in CI).
        $b = $this->build(true, CheckoutConfig::MODE_EMBEDDED, false, true, false);
        $b['controller']->execute();
        $this->assertSame('SAMEORIGIN', $b['rawHeaders']['X-Frame-Options'] ?? null);
    }
}
