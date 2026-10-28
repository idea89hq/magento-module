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
use Magento\Framework\UrlInterface;
use Magento\Framework\Validator\EmailAddress;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Quote\Api\ShipmentEstimationInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\SalesRule\Model\CouponFactory;
use Magento\CheckoutAgreements\Api\CheckoutAgreementsListInterface;
use Magento\CheckoutAgreements\Model\Api\SearchCriteria\ActiveStoreAgreementsFilter;

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
        private readonly CurrencyFactory $currencyFactory,
        // Appended for the same reason: the store's own spelling of a code.
        private readonly CouponFactory $couponFactory,
        // The store's own Terms and Conditions (Stores > Terms and Conditions).
        private readonly CheckoutAgreementsListInterface $agreementsList,
        private readonly ActiveStoreAgreementsFilter $activeAgreementsFilter,
        // Card and wallet payments through the merchant's Stripe module.
        private readonly StripeInChat $stripeInChat,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly UrlInterface $url
    ) {}

    /**
     * The store's active Terms and Conditions for this store view, when the
     * merchant has turned them on (Sales > Checkout > Checkout Options >
     * Enable Terms and Conditions), the same list Magento's own checkout
     * shows. 'manual' ones need the shopper to tick a box; the others are
     * shown as a line of text. Content is plain text: HTML content is
     * flattened here so the widget never renders merchant markup.
     *
     * @return array<int, array{id: string, title: string, label: string, content: string, manual: bool}>
     */
    public function agreements(): array
    {
        if (!$this->scopeConfig->isSetFlag('checkout/options/enable_agreements', ScopeInterface::SCOPE_STORE)) {
            return [];
        }
        try {
            $list = $this->agreementsList->getList($this->activeAgreementsFilter->buildSearchCriteria());
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($list as $agreement) {
            $content = (string) $agreement->getContent();
            if ($agreement->getIsHtml()) {
                $content = $this->htmlToText($content);
            }
            $title = trim((string) $agreement->getName());
            $label = trim((string) $agreement->getCheckboxText());
            $out[] = [
                'id'      => (string) $agreement->getAgreementId(),
                'title'   => $title,
                'label'   => $label !== '' ? $label : $title,
                'content' => trim($content),
                'manual'  => (int) $agreement->getMode() === 1,
            ];
        }
        return $out;
    }

    private function phoneRequired(): bool
    {
        $show = $this->scopeConfig->getValue('customer/address/telephone_show', ScopeInterface::SCOPE_STORE);
        // Unset means core's own default, which is 'req'.
        return $show === null || (string) $show === 'req';
    }

        private function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<\s*br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</\s*(p|div|li|h[1-6]|tr|ul|ol)\s*>#i', "\n", $html);
        $html = (string) preg_replace('#<\s*li[^>]*>#i', '- ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        return (string) preg_replace("/\n\s*\n\s*(\n\s*)+/", "\n\n", $text);
    }

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
     *     allowed_countries: array<int, array{code: string, name: string}>,
     *     default_country: string
     * }
     */
    public function context(): array
    {
        $quote = $this->checkoutSession->getQuote();
        // Fresh figures: the stored totals can predate a price, rule or tax
        // change (the basket step showed a pre-VAT total once VAT was set
        // up). Recalculated in memory only; nothing is saved by a GET.
        try {
            $quote->collectTotals();
        } catch (\Throwable $e) {
            // Stored totals are still a reasonable answer.
        }

        $methods = [];
        foreach ($this->allowedMethods() as $code => $method) {
            if (!$this->isAvailableFor($method, $quote)) {
                continue;
            }
            $methods[] = ['code' => $code, 'title' => (string) $method->getTitle()];
        }

        return [
            'logged_in'         => $this->customerSession->isLoggedIn(),
            'item_count'        => (int) $quote->getItemsCount(),
            'currency'          => $this->currencyCode($quote),
            'currency_symbol'   => $this->currencySymbol($quote),
            'subtotal'          => $this->formatAmount((float) $quote->getData('subtotal')),
            'grand_total'       => $this->formatAmount((float) $quote->getData('grand_total')),
            'methods'           => $methods,
            // The full breakdown and the applied discount code, so the basket
            // step can show what the order costs before any address.
            'totals'            => $this->totals($quote),
            'coupons'           => true,
            'needs_shipping'    => !$quote->isVirtual(),
            'allowed_countries' => $this->allowedCountries(),
            'default_country'   => $this->defaultCountry($quote),
            // What this shopper already gave for this basket, so the chat
            // form opens filled in after a page refresh or a visit to the
            // store's own checkout page.
            'prefill'           => $this->prefill($quote),
            // The store's Terms and Conditions, shown before Place order.
            'agreements'        => $this->agreements(),
            // Customers > Address Templates > Show Telephone; Magento's own
            // default is required, and placeOrder() refuses a blank one.
            'phone_required'    => $this->phoneRequired(),
            // Present only when the chat can take a card or wallet payment:
            // what the widget needs to show Stripe's own fields.
            'stripe'            => $this->offersStripe($methods) ? $this->stripeInChat->clientConfig($quote) : null,
        ];
    }

    /**
     * @param array<int, array{code: string, title: string}> $methods
     */
    private function offersStripe(array $methods): bool
    {
        foreach ($methods as $m) {
            if ($m['code'] === StripeInChat::METHOD) {
                return true;
            }
        }
        return false;
    }

    /**
     * The email and delivery details already on this session's quote. The
     * quote is bound to the shopper's own session, so this only ever returns
     * what they typed (or their signed-in account holds). Empty fields are
     * left out.
     *
     * @return array<string,string>
     */
    private function prefill(Quote $quote): array
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $street = $address !== null ? $address->getStreet() : [];
        $email = trim((string) $quote->getData('customer_email'));
        if ($email === '' && $address !== null) {
            $email = trim((string) $address->getEmail());
        }
        $out = [
            'email'      => $email,
            'firstname'  => $address !== null ? trim((string) $address->getFirstname()) : '',
            'lastname'   => $address !== null ? trim((string) $address->getLastname()) : '',
            'street'     => is_array($street) && isset($street[0]) ? trim((string) $street[0]) : '',
            'city'       => $address !== null ? trim((string) $address->getCity()) : '',
            'postcode'   => $address !== null ? trim((string) $address->getPostcode()) : '',
            'country_id' => $address !== null ? strtoupper(trim((string) $address->getCountryId())) : '',
            'telephone'  => $address !== null ? trim((string) $address->getTelephone()) : '',
        ];
        return array_filter($out, static fn (string $v): bool => $v !== '');
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
        // Magento's own rule for a telephone (refused only at placeOrder
        // otherwise, after the shopper has been told the address is fine).
        $telephone = isset($payload['telephone']) ? trim((string) $payload['telephone']) : '';
        if ($telephone === '' && $this->phoneRequired()) {
            throw new LocalizedException(__('Enter a phone number.'));
        }
        if ($telephone !== '' && !preg_match('#^[\d\s+().\/ -]+$#u', $telephone)) {
            throw new LocalizedException(__('Enter a phone number using numbers, spaces and + - ( ) . /'));
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
     * Apply a discount code to the shopper's own quote. Magento's own cart
     * price rules decide whether it is valid; a code they reject leaves the
     * quote exactly as it was. Same mechanism as the cart page's "Apply
     * Discount Code" (CouponManagement::set does the same on a cart id).
     *
     * @return array{totals: array<string, mixed>}
     * @throws LocalizedException
     */
    public function applyCoupon(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new LocalizedException(__('Enter a discount code.'));
        }
        if (mb_strlen($code) > 255) {
            throw new LocalizedException(__('That discount code is not valid.'));
        }
        $quote = $this->checkoutSession->getQuote();
        if (!$quote->getItemsCount()) {
            throw new LocalizedException(__('Your cart is empty.'));
        }
        try {
            $previous = (string) $quote->getCouponCode();
            // The store's own spelling ("WELCOME10"), whatever the shopper
            // typed: Magento matches codes case-insensitively but shows them
            // as they are stored.
            $code = $this->storedCouponCode($code) ?? $code;
            $this->recollect($quote, $code);
            if (strcasecmp((string) $quote->getCouponCode(), $code) !== 0) {
                // Rejected by the rules: put the previous code back.
                $this->recollect($quote, $previous);
                throw new LocalizedException(__('The code "%1" is not valid, or does not apply to this basket.', $code));
            }
            return ['totals' => $this->totals($quote)];
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LocalizedException(__('We could not apply that code. Try again.'));
        }
    }

    /**
     * @return array{totals: array<string, mixed>}
     * @throws LocalizedException
     */
    public function removeCoupon(): array
    {
        $quote = $this->checkoutSession->getQuote();
        try {
            $this->recollect($quote, '');
            return ['totals' => $this->totals($quote)];
        } catch (\Throwable $e) {
            throw new LocalizedException(__('We could not remove that code. Try again.'));
        }
    }

    private function storedCouponCode(string $code): ?string
    {
        try {
            $stored = (string) $this->couponFactory->create()->loadByCode($code)->getCode();
            return $stored !== '' ? $stored : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function recollect(Quote $quote, string $couponCode): void
    {
        if (!$quote->isVirtual()) {
            $quote->getShippingAddress()->setCollectShippingRates(true);
        }
        $quote->setCouponCode($couponCode);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
    }

    /**
     * @param array<int, string|int> $agreementIds Terms and Conditions the shopper ticked.
     * @return array{order_id: string, total: string, currency: string}
     * @throws LocalizedException
     */
    public function place(string $paymentMethod, array $agreementIds = [], string $confirmationToken = ''): array
    {
        // The store's own Terms and Conditions: every one that needs a tick
        // must have been ticked. Magento's own checkout enforces this in its
        // payment-information plugin, which placeOrder() does not go through.
        $given = array_map('strval', $agreementIds);
        foreach ($this->agreements() as $agreement) {
            if ($agreement['manual'] && !in_array($agreement['id'], $given, true)) {
                throw new LocalizedException(__('Tick the box to agree to the terms and conditions.'));
            }
        }

        // Re-check server side. Never trust the code the request sent —
        // an empty allowlist here means an empty $allowed array, and this
        // throws for every method, exactly as it should.
        $allowed = $this->allowedMethods();
        if (!isset($allowed[$paymentMethod])) {
            throw new LocalizedException(__('That payment method is not available.'));
        }

        // A Stripe payment is the shopper's details in Stripe's own fields,
        // turned into a ConfirmationToken by Stripe.js. No token, no payment.
        $isStripe = $paymentMethod === StripeInChat::METHOD;
        if ($isStripe && !preg_match('/^ctoken_[A-Za-z0-9]{8,}$/', $confirmationToken)) {
            throw new LocalizedException(__('Enter your payment details.'));
        }

        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getItemsCount()) {
            throw new LocalizedException(__('Your cart is empty.'));
        }

        // Allowlisted is not the same as usable for this cart: Magento's own
        // checkout also asks the method (minimum/maximum order total,
        // allowed countries, zero-total-only methods such as "free").
        if (!$this->isAvailableFor($allowed[$paymentMethod], $quote)) {
            throw new LocalizedException(__('That payment method is not available.'));
        }

        if (!$this->customerSession->isLoggedIn()) {
            $quote->setCheckoutMethod(CartManagementInterface::METHOD_GUEST);
            $quote->setCustomerIsGuest(true);
            if (trim((string) $quote->getData('customer_email')) === '') {
                throw new LocalizedException(__('Enter your email address before placing the order.'));
            }
        }

        if ($isStripe) {
            // importData, not setMethod: it runs the method's assignData(),
            // which is where the Stripe module stores the token it confirms
            // the PaymentIntent with.
            $quote->getPayment()->importData([
                'method'          => $paymentMethod,
                'additional_data' => ['confirmation_token' => $confirmationToken],
            ]);
        } else {
            $quote->getPayment()->setMethod($paymentMethod);
        }
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
            $orderId = $this->cartManagement->placeOrder($quote->getId());
        } catch (\Throwable $e) {
            // The card needs 3-D Secure. The Stripe module stops before the
            // order exists and hands back the PaymentIntent's client secret
            // in the message; the browser authenticates and calls place()
            // again, and the module then finds the PaymentIntent paid.
            $secret = $isStripe ? $this->authenticationSecret($e) : null;
            if ($secret !== null) {
                return ['requires_action' => true, 'client_secret' => $secret];
            }
            if ($e instanceof LocalizedException) {
                // Magento's own LocalizedExceptions are already written to be
                // shopper-safe (e.g. "out of stock") — pass them through as-is.
                throw $e;
            }
            if ($isStripe && trim($e->getMessage()) !== '') {
                // Stripe's own decline text ("Your card was declined.") is
                // written for shoppers; the module shows it on its checkout.
                throw new LocalizedException(__($e->getMessage()));
            }
            throw new LocalizedException(__('We could not place your order. Please try again.'));
        }

        $result = [
            'order_id' => (string) $quote->getReservedOrderId(),
            'total'    => $this->formatAmount((float) $quote->getData('grand_total')),
            'currency' => $this->currencyCode($quote),
        ];
        if ($isStripe) {
            $result += $this->stripeOutcome((int) $orderId);
        }
        return $result;
    }

    /**
     * The client secret from the Stripe module's "Authentication Required:
     * <client_secret>" stop, or null for any other failure. Only a secret
     * of the shape Stripe issues is passed on.
     */
    private function authenticationSecret(\Throwable $e): ?string
    {
        $prefix = 'Authentication Required: ';
        $message = $e->getMessage();
        if (!str_starts_with($message, $prefix)) {
            return null;
        }
        $secret = trim(substr($message, strlen($prefix)));
        return preg_match('/^(?:pi|seti)_[A-Za-z0-9]+_secret_[A-Za-z0-9]+$/', $secret) ? $secret : null;
    }

    /**
     * A paid Stripe order reports its status. One the module left waiting
     * for the shopper (a payment type it does not stop for, if the merchant
     * changed that setting) must not be called confirmed: the widget sends
     * the shopper to the module's own return page, which finishes it.
     *
     * @return array{status: string, continue_url?: string}
     */
    private function stripeOutcome(int $orderId): array
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            return ['status' => ''];
        }
        if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
            return ['status' => 'pending_payment', 'continue_url' => $this->url->getUrl('stripe/payment/index')];
        }
        return ['status' => (string) $order->getStatus()];
    }

    /**
     * Payment methods the merchant has explicitly allowed AND that are
     * currently active in Magento AND that complete without the shopper
     * paying anywhere else, keyed by code. Empty on both an empty allowlist
     * and a merchant who allowlisted a method they've since disabled —
     * either way, nothing is offered.
     *
     * Offline only: placeOrder() is the last thing this flow does, so a
     * method that takes payment after it (a card form, a redirect to a
     * hosted payment page) would leave an unpaid order that the widget then
     * reports as confirmed. Seen live with Stripe's redirect flow: the order
     * sat in pending_payment with an open Checkout Session nobody was sent to.
     * The exception is Stripe's own card and wallet method when the merchant
     * has turned on StripeInChat: the shopper pays in Stripe's fields in the
     * chat before the order is placed.
     *
     * @return array<string,MethodInterface>
     */
    private function allowedMethods(): array
    {
        $allowlist = array_flip($this->checkoutConfig->getNativeMethods());
        $methods = [];
        foreach ($this->paymentConfig->getActiveMethods() as $code => $method) {
            // The one method that takes payment and still completes here:
            // Stripe, through its own fields in the chat (StripeInChat). Its
            // switch is the merchant's opt-in, not the offline allowlist.
            if ($code === StripeInChat::METHOD && $this->stripeInChat->isAvailable()) {
                $methods[$code] = $method;
                continue;
            }
            if (!isset($allowlist[$code]) || !$method->isOffline()) {
                continue;
            }
            $methods[$code] = $method;
        }
        return $methods;
    }

    /**
     * The method's own answer for this cart, as Magento's checkout asks it.
     * A method that throws is treated as unavailable rather than breaking
     * the whole list.
     */
    private function isAvailableFor(MethodInterface $method, Quote $quote): bool
    {
        try {
            return (bool) $method->isAvailable($quote);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The country the delivery form starts on.
     */
    private function defaultCountry(Quote $quote): string
    {
        // The country the delivery form starts on: the quote's own shipping
        // country when it has one (a signed-in customer's address, or a
        // country the shopper already chose in the cart's estimator), else
        // the store's default country. '' when neither is a 2-letter code;
        // the widget then starts on the first allowed country.
        $shipping = $quote->getShippingAddress();
        $code = $shipping ? strtoupper(trim((string) $shipping->getCountryId())) : '';
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            $code = strtoupper(trim((string) $this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_STORE)));
        }
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
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
     * subtotal and grand_total keep their old meaning for older widgets.
     * breakdown is what the widget shows, every amount from Magento's own
     * address totals, on the store's own basis so the lines always add up:
     *
     *   basis 'incl' (the store's cart shows prices including tax, most UK stores):
     *     subtotal + shipping + discount = grand_total; tax is the VAT
     *     already inside those amounts ("Includes £x VAT").
     *   basis 'excl': subtotal + shipping + discount + tax = grand_total.
     *
     * shipping is null until a delivery method is chosen (or nothing ships);
     * discount is negative and null when none; tax null when none.
     *
     * @return array{subtotal: string, grand_total: string, breakdown: array<string, mixed>}
     */
    private function totals(Quote $quote): array
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        // The store's own cart display ("Display Subtotal": 1 excluding tax,
        // 2 including, 3 both), so the chat shows what its cart page shows.
        $incl = (int) $this->scopeConfig->getValue('tax/cart_display/subtotal', ScopeInterface::SCOPE_STORE) >= 2;
        // A quote always has an address object in practice; without one the
        // breakdown is just the subtotal, never an error.
        $hasMethod = $address !== null && !$quote->isVirtual() && trim((string) $address->getShippingMethod()) !== '';
        $discount = $address !== null ? (float) $address->getDiscountAmount() : 0.0;
        $tax = $address !== null ? (float) $address->getTaxAmount() : 0.0;
        $subtotal = $address === null ? (float) $quote->getData('subtotal')
            : ($incl ? (float) $address->getSubtotalInclTax() : (float) $address->getSubtotal());
        $shipping = $address === null ? 0.0
            : ($incl ? (float) $address->getShippingInclTax() : (float) $address->getShippingAmount());
        $code = trim((string) $quote->getCouponCode());
        return [
            // Stripe's fields must show the same amount the order will be
            // placed for; the widget updates them from this on every change.
            'stripe_amount' => $this->stripeInChat->isAvailable() ? $this->stripeInChat->amount($quote) : null,
            'subtotal'    => $this->formatAmount((float) $quote->getData('subtotal')),
            'grand_total' => $this->formatAmount((float) $quote->getData('grand_total')),
            'breakdown'   => [
                'basis'          => $incl ? 'incl' : 'excl',
                'subtotal'       => $this->formatAmount($subtotal),
                'shipping'       => $hasMethod ? $this->formatAmount($shipping) : null,
                'shipping_label' => $hasMethod ? (string) $address->getShippingDescription() : '',
                'discount'       => abs($discount) > 0.0001 ? $this->formatAmount($discount) : null,
                'discount_label' => $address !== null ? trim((string) $address->getDiscountDescription()) : '',
                'tax'            => abs($tax) > 0.0001 ? $this->formatAmount($tax) : null,
                'coupon_code'    => $code !== '' ? $code : null,
                'grand_total'    => $this->formatAmount((float) $quote->getData('grand_total')),
            ],
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
