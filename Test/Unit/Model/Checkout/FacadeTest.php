<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Checkout;

use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\Checkout\Facade;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\Config\Source\Country as CountrySource;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Api\ShipmentEstimationInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Idea89\Assistant\Model\Checkout\Facade
 */
class FacadeTest extends TestCase
{
    /** @var CheckoutSession&MockObject */
    private $checkoutSession;

    /** @var CustomerSession&MockObject */
    private $customerSession;

    /** @var CartRepositoryInterface&MockObject */
    private $cartRepository;

    /** @var ShipmentEstimationInterface&MockObject */
    private $shippingEstimator;

    /** @var CartManagementInterface&MockObject */
    private $cartManagement;

    /** @var AddressInterfaceFactory&MockObject */
    private $addressFactory;

    /** @var CheckoutConfig&MockObject */
    private $checkoutConfig;

    /** @var PaymentConfig&MockObject */
    private $paymentConfig;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var CurrencyFactory&MockObject */
    private $currencyFactory;

    /** @var CountrySource&MockObject */
    private $countrySource;

    private Facade $facade;

    protected function setUp(): void
    {
        $this->checkoutSession   = $this->createMock(CheckoutSession::class);
        $this->customerSession   = $this->createMock(CustomerSession::class);
        $this->cartRepository    = $this->createMock(CartRepositoryInterface::class);
        $this->shippingEstimator = $this->createMock(ShipmentEstimationInterface::class);
        $this->cartManagement    = $this->createMock(CartManagementInterface::class);
        $this->addressFactory    = $this->createMock(AddressInterfaceFactory::class);
        $this->checkoutConfig    = $this->createMock(CheckoutConfig::class);
        $this->paymentConfig     = $this->createMock(PaymentConfig::class);
        $this->scopeConfig       = $this->createMock(ScopeConfigInterface::class);
        $this->countrySource     = $this->createMock(CountrySource::class);
        // Left unstubbed: create() returns null on the mock, currencySymbol()
        // catches the resulting error and falls back to the currency code, so
        // the existing assertions on "GBP" still hold. A test that cares about
        // the symbol stubs it explicitly.
        $this->currencyFactory   = $this->createMock(CurrencyFactory::class);

        // Deliberately NOT stubbed with a blanket default here: PHPUnit
        // checks method() rules in registration order and uses the first
        // match, so a setUp()-level willReturn() would silently win over
        // any per-test override further down. Every existing test predates
        // allowed_countries and never calls getValue() through it; an
        // unstubbed mock returns null, which (string) casts to '' inside
        // Facade::allowedCountries() — the same "merchant hasn't restricted
        // anything" default Magento itself ships.
        $this->facade = new Facade(
            $this->checkoutSession,
            $this->customerSession,
            $this->cartRepository,
            $this->shippingEstimator,
            $this->cartManagement,
            $this->addressFactory,
            $this->checkoutConfig,
            $this->paymentConfig,
            $this->scopeConfig,
            $this->countrySource,
            $this->currencyFactory
        );
    }

    /**
     * @param array<string,MockObject> $activeMethods code => MethodInterface mock
     */
    private function stubActiveMethods(array $activeMethods): void
    {
        $this->paymentConfig->method('getActiveMethods')->willReturn($activeMethods);
    }

    private function makeMethod(string $code, string $title): MethodInterface
    {
        $method = $this->createMock(MethodInterface::class);
        $method->method('getTitle')->willReturn($title);
        return $method;
    }

    // ------------------------------------------------------------------
    // context()
    // ------------------------------------------------------------------

    public function testContextReportsLoggedInAndItemCount(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getItemsCount')->willReturn(3);
        $quote->method('isVirtual')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->checkoutConfig->method('getNativeMethods')->willReturn([]);
        $this->stubActiveMethods([]);

        $context = $this->facade->context();

        $this->assertTrue($context['logged_in']);
        $this->assertSame(3, $context['item_count']);
    }

    public function testContextReturnsOnlyMethodsBothActiveAndAllowlisted(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        // Active in Magento: checkmo, braintree. Allowlisted: checkmo, freeshipping
        // (freeshipping is NOT active in Magento — must not appear either).
        $this->stubActiveMethods([
            'checkmo'   => $this->makeMethod('checkmo', 'Check / Money order'),
            'braintree' => $this->makeMethod('braintree', 'Credit Card (Braintree)'),
        ]);
        $this->checkoutConfig->method('getNativeMethods')->willReturn(['checkmo', 'freeshipping']);

        $context = $this->facade->context();

        $this->assertSame([['code' => 'checkmo', 'title' => 'Check / Money order']], $context['methods']);
    }

    public function testContextReturnsEmptyMethodsWhenAllowlistIsEmpty(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->stubActiveMethods([
            'checkmo'   => $this->makeMethod('checkmo', 'Check / Money order'),
            'braintree' => $this->makeMethod('braintree', 'Credit Card (Braintree)'),
        ]);
        $this->checkoutConfig->method('getNativeMethods')->willReturn([]);

        $context = $this->facade->context();

        $this->assertSame([], $context['methods']);
    }

    public function testContextReturnsEmptyAllowedCountriesWhenTheMerchantHasNotRestrictedAny(): void
    {
        // general/country/allow blank is core Magento's own default ("ship
        // anywhere"). scopeConfig is deliberately left unstubbed here — an
        // unconfigured mock call returns null, which (string) casts to ''
        // inside allowedCountries(), the same as a real blank config value.
        $quote = $this->createMock(Quote::class);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->checkoutConfig->method('getNativeMethods')->willReturn([]);
        $this->stubActiveMethods([]);
        $this->countrySource->expects($this->never())->method('toOptionArray');

        $context = $this->facade->context();

        $this->assertSame([], $context['allowed_countries']);
    }

    public function testContextReturnsOnlyAllowlistedCountriesWithRealNamesInAllowlistOrder(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->checkoutConfig->method('getNativeMethods')->willReturn([]);
        $this->stubActiveMethods([]);

        // Allowlist: US, GB (this order). Directory knows FR too, which must
        // not appear since it's not in general/country/allow.
        $this->scopeConfig->method('getValue')->willReturn('US,GB');
        $this->countrySource->method('toOptionArray')->with(true)->willReturn([
            ['value' => 'GB', 'label' => 'United Kingdom'],
            ['value' => 'US', 'label' => 'United States'],
            ['value' => 'FR', 'label' => 'France'],
        ]);

        $context = $this->facade->context();

        $this->assertSame(
            [
                ['code' => 'US', 'name' => 'United States'],
                ['code' => 'GB', 'name' => 'United Kingdom'],
            ],
            $context['allowed_countries']
        );
    }

    public function testContextFallsBackToTheCodeItselfWhenDirectoryHasNoNameForAnAllowlistedCountry(): void
    {
        // Defensive: an allowlisted code the directory collection doesn't
        // recognise (e.g. a superseded ISO code) must still round-trip
        // usably rather than silently vanishing from the list.
        $quote = $this->createMock(Quote::class);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->checkoutConfig->method('getNativeMethods')->willReturn([]);
        $this->stubActiveMethods([]);

        $this->scopeConfig->method('getValue')->willReturn('ZZ');
        $this->countrySource->method('toOptionArray')->willReturn([
            ['value' => 'GB', 'label' => 'United Kingdom'],
        ]);

        $context = $this->facade->context();

        $this->assertSame([['code' => 'ZZ', 'name' => 'ZZ']], $context['allowed_countries']);
    }

    // ------------------------------------------------------------------
    // setAddress()
    // ------------------------------------------------------------------

    public function testSetAddressRejectsAPayloadMissingRequiredFieldsBeforeTouchingTheQuote(): void
    {
        $this->checkoutSession->expects($this->never())->method('getQuote');

        $this->expectException(LocalizedException::class);

        $this->facade->setAddress(['postcode' => '90210', 'country_id' => 'US']); // street missing
    }

    public function testSetAddressOnAVirtualQuoteReturnsNoShippingMethods(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getData')->willReturn(0);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $address = $this->createMock(AddressInterface::class);
        $this->addressFactory->method('create')->willReturn($address);

        $this->shippingEstimator->expects($this->never())->method('estimateByExtendedAddress');

        $result = $this->facade->setAddress([
            'postcode'   => '90210',
            'country_id' => 'US',
            'street'     => '123 Main St',
        ]);

        $this->assertFalse($result['needs_shipping']);
        $this->assertSame([], $result['shipping_methods']);
    }

    // ------------------------------------------------------------------
    // place()
    // ------------------------------------------------------------------

    public function testPlaceWithAMethodNotOnTheAllowlistThrowsAndNeverPlacesTheOrder(): void
    {
        $this->stubActiveMethods([
            'checkmo' => $this->makeMethod('checkmo', 'Check / Money order'),
        ]);
        $this->checkoutConfig->method('getNativeMethods')->willReturn(['checkmo']);

        $this->checkoutSession->expects($this->never())->method('getQuote');
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->expectException(LocalizedException::class);

        $this->facade->place('braintree');
    }

    public function testPlaceReturnsTheIncrementIdFromTheCreatedOrder(): void
    {
        $this->stubActiveMethods([
            'checkmo' => $this->makeMethod('checkmo', 'Check / Money order'),
        ]);
        $this->checkoutConfig->method('getNativeMethods')->willReturn(['checkmo']);
        $this->customerSession->method('isLoggedIn')->willReturn(true);

        $payment = $this->createMock(QuotePayment::class);
        $payment->expects($this->once())->method('setMethod')->with('checkmo');

        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getQuoteCurrencyCode')->willReturn('GBP');

        $quote = $this->createMock(Quote::class);
        $quote->method('getItemsCount')->willReturn(2);
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('reserveOrderId')->willReturnSelf();
        $quote->method('getReservedOrderId')->willReturn('100000123');
        $quote->method('getData')->willReturnMap([
            ['grand_total', null, 42.5],
        ]);
        $quote->method('getCurrency')->willReturn($currency);
        $quote->method('getId')->willReturn(7);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->cartRepository->expects($this->once())->method('save')->with($quote);
        $this->cartManagement->expects($this->once())->method('placeOrder')->with(7)->willReturn(99);

        $result = $this->facade->place('checkmo');

        $this->assertSame('100000123', $result['order_id']);
        $this->assertSame('42.50', $result['total']);
        $this->assertSame('GBP', $result['currency']);
    }

    public function testPlaceOnAnEmptyQuoteThrowsRatherThanCreatingAZeroItemOrder(): void
    {
        $this->stubActiveMethods([
            'checkmo' => $this->makeMethod('checkmo', 'Check / Money order'),
        ]);
        $this->checkoutConfig->method('getNativeMethods')->willReturn(['checkmo']);

        $quote = $this->createMock(Quote::class);
        $quote->method('getItemsCount')->willReturn(0);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->expectException(LocalizedException::class);

        $this->facade->place('checkmo');
    }

    // ------------------------------------------------------------------
    // Address identity - fix-round addition (round 1)
    //
    // Magento\Quote\Model\Quote::setShippingAddress()/setBillingAddress()
    // each merge the passed object's data into whichever address the quote
    // already owns for that type - Quote::_getAddressByType() auto-creates
    // one when none exists, it never returns null, so the "replace the
    // object outright" branch never runs on this Magento version. That
    // merge is Magento's own shipped code, not this module's, and
    // re-verifying it here would mean faking Magento's DB-backed address
    // collection machinery inside a unit test with no DB - out of scope
    // for this suite (see phpunit.xml.dist's own header: unit only).
    //
    // What IS this module's responsibility, and what this test pins, is
    // narrower: that setAddress() builds exactly ONE address object,
    // populates it with the submitted payload, and hands that SAME object
    // to both setShippingAddress() and setBillingAddress() - never two
    // different objects, never an empty one, never mutated between the
    // two calls. If a future change accidentally cloned the address or
    // built a second, differently-populated one for billing, this test
    // fails; a merge-semantics regression inside Magento's own Quote code
    // would not be caught here and would need an integration test.
    // ------------------------------------------------------------------

    public function testSetAddressGivesShippingAndBillingTheSameAddressCarryingThePayload(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $address->expects($this->once())->method('setCountryId')->with('US');
        $address->expects($this->once())->method('setPostcode')->with('90210');
        $address->expects($this->once())->method('setStreet')->with(['123 Main St']);
        $this->addressFactory->method('create')->willReturn($address);

        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getData')->willReturn(0);
        $quote->method('getId')->willReturn(7);
        $quote->expects($this->once())->method('setShippingAddress')->with($this->identicalTo($address));
        $quote->expects($this->once())->method('setBillingAddress')->with($this->identicalTo($address));
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $this->shippingEstimator->method('estimateByExtendedAddress')->willReturn([]);

        $this->facade->setAddress([
            'postcode'   => '90210',
            'country_id' => 'US',
            'street'     => '123 Main St',
        ]);
    }

    // ------------------------------------------------------------------
    // setShippingMethod() - fix-round addition (round 1): this method had
    // zero coverage; Task 3.3 wires a controller to it next.
    // ------------------------------------------------------------------

    public function testSetShippingMethodSetsTheMethodAndReturnsTotals(): void
    {
        // Quote\Address::setShippingMethod()/setCollectShippingRates() are
        // @method-annotated DataObject magic, not real declared methods on
        // the class (same as getGrandTotal() elsewhere in this suite).
        // PHPUnit's createMock() overrides EVERY public method it finds via
        // reflection, and that includes DataObject's own __call() — so an
        // unconfigured mock's __call() returns null directly and NEVER
        // reaches the real DataObject::__call()'s dispatch to setData().
        // Confirmed empirically: asserting on setData() directly sees zero
        // calls, because the mock's own __call() override short-circuits
        // before the real magic dispatch logic ever runs. __call() itself
        // is a real, reflected, mockable method though (that's exactly why
        // the unconfigured case returns null instead of a fatal "undefined
        // method" error) — stubbing it directly is therefore the only way
        // to observe a magic setter invocation on a mock in this PHPUnit
        // version, now that addMethods() (the old workaround) is gone.
        $shippingAddress = $this->createMock(QuoteAddress::class);
        $magicCalls = [];
        $shippingAddress->expects($this->exactly(2))
            ->method('__call')
            ->willReturnCallback(function ($name, $args) use (&$magicCalls, $shippingAddress) {
                $magicCalls[] = [$name, $args];
                return $shippingAddress;
            });

        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($shippingAddress);
        $quote->method('getData')->willReturnMap([
            ['subtotal', null, 10.0],
            ['grand_total', null, 15.0],
        ]);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->cartRepository->expects($this->once())->method('save')->with($quote);

        $result = $this->facade->setShippingMethod('flatrate', 'flatrate');

        $this->assertContains(['setShippingMethod', ['flatrate_flatrate']], $magicCalls);
        $this->assertContains(['setCollectShippingRates', [true]], $magicCalls);
        $this->assertSame(['totals' => ['subtotal' => '10.00', 'grand_total' => '15.00']], $result);
    }

    public function testSetShippingMethodRejectsABlankCarrierOrMethodBeforeTouchingTheQuote(): void
    {
        $this->checkoutSession->expects($this->never())->method('getQuote');

        $this->expectException(LocalizedException::class);

        $this->facade->setShippingMethod('', 'flatrate');
    }

    public function testSetShippingMethodThrowsOnAVirtualQuote(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(true);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->cartRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);

        $this->facade->setShippingMethod('flatrate', 'flatrate');
    }

    /**
     * The checkout steps render currency in front of every amount. Sending
     * only the code put "GBP 10.00" in front of the shopper while the
     * storefront beside it said "£10.00" for the same order.
     */
    public function testContextPublishesTheCurrencySymbolNotJustTheCode(): void
    {
        $quote = $this->createMock(Quote::class);
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getQuoteCurrencyCode')->willReturn('GBP');
        $quote->method('getCurrency')->willReturn($currency);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->checkoutConfig->method('getNativeMethods')->willReturn([]);
        $this->paymentConfig->method('getActiveMethods')->willReturn([]);

        $currencyModel = $this->createMock(\Magento\Directory\Model\Currency::class);
        $currencyModel->method('load')->willReturnSelf();
        $currencyModel->method('getCurrencySymbol')->willReturn('£');
        $this->currencyFactory->method('create')->willReturn($currencyModel);

        $ctx = $this->facade->context();

        $this->assertSame('£', $ctx['currency_symbol']);
        // The code stays alongside it: the beacon and any consumer that needs
        // an ISO code should not have to reverse a glyph back into one.
        $this->assertSame('GBP', $ctx['currency']);
    }

    /**
     * A missing symbol must degrade to the code, never to an empty string:
     * "GBP 10.00" reads oddly but a bare "10.00" with no currency at all is
     * genuinely ambiguous on a multi-currency store.
     */
    public function testCurrencySymbolFallsBackToTheCode(): void
    {
        $quote = $this->createMock(Quote::class);
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getQuoteCurrencyCode')->willReturn('GBP');
        $quote->method('getCurrency')->willReturn($currency);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->checkoutConfig->method('getNativeMethods')->willReturn([]);
        $this->paymentConfig->method('getActiveMethods')->willReturn([]);

        $this->currencyFactory->method('create')
            ->willThrowException(new \RuntimeException('no directory data'));

        $ctx = $this->facade->context();

        $this->assertSame('GBP', $ctx['currency_symbol']);
    }
}
