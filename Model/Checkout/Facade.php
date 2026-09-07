<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Checkout;

use Idea89\Assistant\Model\CheckoutConfig;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\Config\Source\Country as CountrySource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\EmailAddress;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Quote\Api\ShipmentEstimationInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * The only door onto the quote for Tier 3 (native checkout). Every
 * controller in Controller/Checkout/* that touches an order goes through
 * exactly these four methods — nothing else in the module is allowed to
 * call the Quote API directly for a native-checkout flow.
 *
 * Two guarantees this class exists to hold, both non-negotiable:
 *
 *   1. The quote is always $this->checkoutSession->getQuote() — the
 *      shopper's own session. No method here accepts a cart or quote id
 *      from the request; a quote id would be a second, weaker credential
 *      than the session cookie the shopper already has.
 *   2. A payment method code is never trusted from the caller. Every method
 *      that can reach placeOrder() re-derives the allowed set from
 *      CheckoutConfig::getNativeMethods() intersected with Magento's own
 *      active methods, and refuses anything outside it — including when
 *      the merchant has configured no methods at all, which must silently
 *      produce an empty list, never an error.
 *
 * Every public method here throws only Magento\Framework\Exception\
 * LocalizedException, and only with messages safe to show a shopper
 * verbatim — no SQL, no class names, no stack traces. Controllers are
 * expected to catch nothing more specific than that.
 */
class Facade
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ShipmentEstimationInterface $shippingEstimator,
        private readonly CartManagementInterface $cartManagement,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly CheckoutConfig $checkoutConfig,
        private readonly PaymentConfig $paymentConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CountrySource $countrySource,
        // Appended rather than inserted: the constructor is called
        // positionally in the unit tests, so a new argument in the middle
        // silently reassigns every dependency after it.
        private readonly CurrencyFactory $currencyFactory
    ) {}

    /**
     * @return array{
     *     logged_in: bool,
     *     item_count: int,
     *     currency: string,
     *     currency_symbol: string,
     *     subtotal: string,
     *     grand_total: string,
     *     methods: array<int, array{code: string, title: string}>,
     *     needs_shipping: bool,
     *     allowed_countries: array<int, array{code: string, name: string}>
     * }
     */
    public function context(): array
    {
        $quote = $this->checkoutSession->getQuote();

        $methods = [];
        foreach ($this->allowedMethods() as $code => $title) {
            $methods[] = ['code' => $code, 'title' => $title];
        }

        return [
            'logged_in'         => $this->customerSession->isLoggedIn(),
            'item_count'        => (int) $quote->getItemsCount(),
            'currency'          => $this->currencyCode($quote),
            'currency_symbol'   => $this->currencySymbol($quote),
            'subtotal'          => $this->formatAmount((float) $quote->getData('subtotal')),
            'grand_total'       => $this->formatAmount((float) $quote->getData('grand_total')),
            'methods'           => $methods,
            'needs_shipping'    => !$quote->isVirtual(),
            'allowed_countries' => $this->allowedCountries(),
        ];
    }

    /**
     * @param array<string,mixed> $payload Delivery details from the conversation.
     *                                     Required: postcode, country_id, street.
     *                                     Optional: city, region, region_id,
     *                                     telephone, firstname, lastname, email.
     * @return array{
     *     shipping_methods: array<int, array{carrier: string, method: string, title: string, amount: string}>,
     *     totals: array{subtotal: string, grand_total: string},
     *     needs_shipping: bool
     * }
     * @throws LocalizedException
     */
    public function setAddress(array $payload): array
    {
        // Validated BEFORE the quote is touched — a shopper who has typed
        // half an address must not leave a half-set address on their quote.
        $this->requireAddressFields($payload);

        $quote = $this->checkoutSession->getQuote();

        $email = isset($payload['email']) ? trim((string) $payload['email']) : '';
        if ($email !== '') {
            $validator = new EmailAddress();
            if (!$validator->isValid($email)) {
                throw new LocalizedException(__('Enter a valid email address.'));
            }
        }

        $address = $this->buildAddress($payload, $email);

        // Everything past this point touches the quote's persistence layer
        // (collectTotals()/save()) or calls out to a shipping carrier
        // (estimateByExtendedAddress()) — any of those can throw something
        // that is not shopper-safe (DB error, malformed region data, a
        // carrier's own exception). Same guard shape as place() below, so
        // this class's own promise ("every public method here throws only
        // LocalizedException, safe to show a shopper") actually holds for
        // all three public methods, not just place().
        try {
            $quote->setShippingAddress($address);
            $quote->setBillingAddress($address);
            if ($email !== '') {
                // Real setter only exists via DataObject magic (Quote has no
                // declared setCustomerEmail()); this is the same mechanism
                // Magento's own guest checkout uses.
                $quote->setData('customer_email', $email);
            }
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            $needsShipping = !$quote->isVirtual();
            $shippingMethods = [];
            if ($needsShipping) {
                foreach ($this->shippingEstimator->estimateByExtendedAddress($quote->getId(), $address) as $rate) {
                    if ($rate->getAvailable() === false) {
                        continue;
                    }
                    $shippingMethods[] = [
                        'carrier' => (string) $rate->getCarrierCode(),
                        'method'  => (string) $rate->getMethodCode(),
                        'title'   => trim(sprintf('%s - %s', (string) $rate->getCarrierTitle(), (string) $rate->getMethodTitle()), ' -'),
                        'amount'  => $this->formatAmount((float) $rate->getAmount()),
                    ];
                }
            }

            return [
                'shipping_methods' => $shippingMethods,
                'totals'           => $this->totals($quote),
                'needs_shipping'   => $needsShipping,
            ];
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LocalizedException(__('We could not save that address. Check the details and try again.'));
        }
    }

    /**
     * @return array{totals: array{subtotal: string, grand_total: string}}
     * @throws LocalizedException
     */
    public function setShippingMethod(string $carrier, string $method): array
    {
        if (trim($carrier) === '' || trim($method) === '') {
            throw new LocalizedException(__('Choose a delivery method.'));
        }

        $quote = $this->checkoutSession->getQuote();

        if ($quote->isVirtual()) {
            throw new LocalizedException(__('This order does not need a delivery method.'));
        }

        try {
            // Two statements, not a fluent chain: DataObject's magic setters
            // return $this on a real Quote\Address, but that's an assumption
            // about magic-method return values, not a real declared
            // contract, and chaining on it is one uncaught \Throwable away
            // from the catch-all below masking a real bug as a generic
            // error. Discarding each setter's return value sidesteps that.
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setShippingMethod($carrier . '_' . $method);
            $shippingAddress->setCollectShippingRates(true);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            return ['totals' => $this->totals($quote)];
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LocalizedException(__('We could not update the delivery method. Try again.'));
        }
    }

    /**
     * @return array{order_id: string, total: string, currency: string}
     * @throws LocalizedException
     */
    public function place(string $paymentMethod): array
    {
        // Re-check server side. Never trust the code the request sent —
        // an empty allowlist here means an empty $allowed array, and this
        // throws for every method, exactly as it should.
        $allowed = $this->allowedMethods();
        if (!isset($allowed[$paymentMethod])) {
            throw new LocalizedException(__('That payment method is not available.'));
        }

        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getItemsCount()) {
            throw new LocalizedException(__('Your cart is empty.'));
        }

        if (!$this->customerSession->isLoggedIn()) {
            $quote->setCheckoutMethod(CartManagementInterface::METHOD_GUEST);
            $quote->setCustomerIsGuest(true);
            if (trim((string) $quote->getData('customer_email')) === '') {
                throw new LocalizedException(__('Enter your email address before placing the order.'));
            }
        }

        $quote->getPayment()->setMethod($paymentMethod);
        // Reserve the increment id on OUR quote object now, before handing
        // the cart id to placeOrder(). CartManagementInterface::placeOrder()
        // only returns the numeric order entity id and reloads its own copy
        // of the quote internally, so this is the only way to hand the
        // shopper back the human-readable order number they'll recognise.
        // reserveOrderId() is idempotent — QuoteManagement calls it again on
        // its reloaded copy and keeps the id we already reserved here.
        $quote->reserveOrderId();
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        try {
            $this->cartManagement->placeOrder($quote->getId());
        } catch (LocalizedException $e) {
            // Magento's own LocalizedExceptions are already written to be
            // shopper-safe (e.g. "out of stock") — pass them through as-is.
            throw $e;
        } catch (\Throwable $e) {
            throw new LocalizedException(__('We could not place your order. Please try again.'));
        }

        return [
            'order_id' => (string) $quote->getReservedOrderId(),
            'total'    => $this->formatAmount((float) $quote->getData('grand_total')),
            'currency' => $this->currencyCode($quote),
        ];
    }

    /**
     * Payment method codes the merchant has explicitly allowed AND that are
     * currently active in Magento, keyed by code with a display title.
     * Empty on both an empty allowlist and a merchant who allowlisted a
     * method they've since disabled — either way, nothing is offered.
     *
     * @return array<string,string>
     */
    private function allowedMethods(): array
    {
        $allowlist = array_flip($this->checkoutConfig->getNativeMethods());
        $methods = [];
        foreach ($this->paymentConfig->getActiveMethods() as $code => $method) {
            if (!isset($allowlist[$code])) {
                continue;
            }
            $methods[$code] = (string) $method->getTitle();
        }
        return $methods;
    }

    /**
     * The store's own shippable-country allowlist (core Magento config,
     * general/country/allow — not a Checkout Experience setting, so this
     * reads ScopeConfigInterface directly rather than going through
     * CheckoutConfig), with real localised names rather than inventing any.
     * Empty when the merchant has not restricted countries at all (the
     * config default), in which case the widget falls back to accepting any
     * 2-letter code — see _ckRenderAddress's client-side validation.
     *
     * @return array<int, array{code: string, name: string}>
     */
    private function allowedCountries(): array
    {
        $raw = (string) $this->scopeConfig->getValue('general/country/allow', ScopeInterface::SCOPE_STORE);
        if ($raw === '') {
            return [];
        }
        $codes = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
        if ($codes === []) {
            return [];
        }

        $names = [];
        foreach ($this->countrySource->toOptionArray(true) as $option) {
            $code = (string) ($option['value'] ?? '');
            if ($code === '') {
                continue;
            }
            $names[$code] = (string) ($option['label'] ?? $code);
        }

        $countries = [];
        foreach ($codes as $code) {
            $countries[] = ['code' => $code, 'name' => $names[$code] ?? $code];
        }
        return $countries;
    }

    /**
     * @param array<string,mixed> $payload
     * @throws LocalizedException
     */
    private function requireAddressFields(array $payload): void
    {
        foreach (['postcode', 'country_id', 'street'] as $field) {
            $value = $payload[$field] ?? null;
            $blank = is_array($value)
                ? array_filter($value, static fn ($line) => trim((string) $line) !== '') === []
                : trim((string) $value) === '';
            if ($blank) {
                throw new LocalizedException(__('Enter a delivery postcode, country and street address.'));
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function buildAddress(array $payload, string $email): AddressInterface
    {
        $street = $payload['street'];
        $streetLines = is_array($street)
            ? array_values(array_filter($street, static fn ($line) => trim((string) $line) !== ''))
            : array_values(array_filter(explode("\n", (string) $street), static fn ($line) => trim($line) !== ''));

        $address = $this->addressFactory->create();
        $address->setCountryId((string) $payload['country_id']);
        $address->setPostcode((string) $payload['postcode']);
        $address->setStreet($streetLines);

        if (isset($payload['city'])) {
            $address->setCity((string) $payload['city']);
        }
        if (isset($payload['region'])) {
            $address->setRegion((string) $payload['region']);
        }
        if (isset($payload['region_id'])) {
            $address->setRegionId((int) $payload['region_id']);
        }
        if (isset($payload['telephone'])) {
            $address->setTelephone((string) $payload['telephone']);
        }
        if (isset($payload['firstname'])) {
            $address->setFirstname((string) $payload['firstname']);
        }
        if (isset($payload['lastname'])) {
            $address->setLastname((string) $payload['lastname']);
        }
        if ($email !== '') {
            $address->setEmail($email);
        }

        return $address;
    }

    /**
     * @return array{subtotal: string, grand_total: string}
     */
    private function totals(Quote $quote): array
    {
        return [
            'subtotal'    => $this->formatAmount((float) $quote->getData('subtotal')),
            'grand_total' => $this->formatAmount((float) $quote->getData('grand_total')),
        ];
    }

    private function currencyCode(Quote $quote): string
    {
        $currency = $quote->getCurrency();
        return $currency ? (string) $currency->getQuoteCurrencyCode() : '';
    }

    /**
     * The display symbol for the quote's currency, e.g. GBP -> "£".
     *
     * The code alone is what the checkout steps used to render, which put
     * "GBP 10.00" in front of the shopper while the storefront beside it and
     * the widget's own basket card both said "£10.00". Same order, two
     * different-looking prices.
     *
     * Falls back to the code when the symbol cannot be resolved: "GBP 10.00"
     * reads oddly but is never WRONG, whereas an empty prefix would render a
     * bare "10.00" with no indication of currency at all.
     */
    private function currencySymbol(Quote $quote): string
    {
        $code = $this->currencyCode($quote);
        if ($code === '') {
            return '';
        }
        try {
            $symbol = (string) $this->currencyFactory->create()->load($code)->getCurrencySymbol();
            return $symbol !== '' ? $symbol : $code;
        } catch (\Throwable $e) {
            return $code;
        }
    }

    private function formatAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
