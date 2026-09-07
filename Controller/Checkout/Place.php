<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Checkout;

use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\CheckoutRateLimit;
use Idea89\Assistant\Model\Checkout\Facade;
use Idea89\Assistant\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /idea89/checkout/place  { "payment_method": "checkmo", "form_key": "…" }
 *
 * The order-placing rung of Tier 3 (native) checkout. This is the one call
 * in the four that spends the shopper's money, which is why it alone uses
 * CheckoutRateLimit::checkPlace() (8/session/10min) rather than the looser
 * checkOther() bucket the other three POST/GET routes share.
 *
 * Same shape as Context.php, plus the CSRF guard every state-changing
 * controller in this file needs: validateForCsrf() below reads the Magento
 * form key out of the JSON body (it can't be in $request->getParam() —
 * that only sees query-string/form-encoded params, and this body is JSON)
 * and bridges it into the request's param bag so the real, unmodified
 * Magento\Framework\Data\Form\FormKey\Validator can do the actual
 * comparison. A payment method code is never trusted from the caller
 * either — Facade::place() re-derives the allowed set server-side.
 */
class Place implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
        private readonly CheckoutConfig $checkoutConfig,
        private readonly CheckoutRateLimit $rateLimit,
        private readonly Facade $facade,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly LoggerInterface $logger,
        private readonly RemoteAddress $remoteAddress
    ) {}

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);

        // 1. Same-origin guard — copied from Controller/Customer/Me.php.
        if (!$this->isSameOrigin()) {
            return $result->setHttpResponseCode(403)
                ->setData(['error' => 'cross_origin_forbidden']);
        }

        // 2. Feature gate — 404, not 403: an unused route should not
        // advertise its own existence.
        if (!$this->config->isEnabled() || !$this->checkoutConfig->isNative()) {
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'not_available']);
        }

        // 3. Rate limit, keyed on the shopper's own session AND their IP
        // (see CheckoutRateLimit's docblock — a session-only bucket is
        // trivially defeated by a client that discards its session cookie).
        // The tight 8/10min session bucket — this call places an order.
        $gate = $this->rateLimit->checkPlace($this->sessionId(), $this->clientIp());
        if (!$gate['allowed']) {
            $result->setHeader('Retry-After', (string) $gate['retry_after'], true);
            return $result->setHttpResponseCode(429)
                ->setData(['error' => 'rate_limited', 'retry_after' => $gate['retry_after']]);
        }

        // 4. Delegate. The facade is the only thing that touches a quote,
        // and it never trusts the payment method code from the caller —
        // it re-derives the allowed set from CheckoutConfig itself.
        $body = $this->decodedBody();
        $paymentMethod = $this->stringField($body, 'payment_method');

        try {
            return $result->setData($this->facade->place($paymentMethod));
        } catch (LocalizedException $e) {
            // Expected shopper-facing outcome, not a fault — not logged as
            // an error. Facade's own contract: every message here is
            // written to be shown to the shopper verbatim.
            return $result->setHttpResponseCode(400)
                ->setData(['error' => 'checkout_error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('[idea89] native checkout place failed', ['exception' => $e]);
            return $result->setHttpResponseCode(500)
                ->setData(['error' => 'internal_error']);
        }
    }

    /**
     * DEVIATION FROM THE BRIEF (verified on the wire, not just read from
     * source): the brief says returning null here yields "Magento's
     * standard 403" on a false from validateForCsrf(). It does not.
     * Magento\Framework\App\Request\CsrfValidator::createException() falls
     * back to a 302 redirect ("Invalid Form Key. Please refresh the page.")
     * when this method returns null and validateForCsrf() returned a
     * concrete false (as opposed to null, which would additionally consult
     * isXmlHttpRequest()) — confirmed with curl against a live bad-form-key
     * POST, see the task report. A 302 to an HTML page breaks the widget's
     * `r.json()` parsing and does not satisfy this module's
     * "{"error": snake_case_code}` contract, so we build the 403 JSON
     * response ourselves instead of returning null.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setHttpResponseCode(403);
        $result->setData(['error' => 'invalid_form_key']);
        return new InvalidRequestException($result);
    }

    /**
     * Validate the Magento form key carried in the JSON body. The real
     * Magento\Framework\Data\Form\FormKey\Validator::validate() reads
     * $request->getParam('form_key'), which is never populated for a JSON
     * POST (no form-encoded body for Magento's request object to parse
     * into params). We decode the body ourselves, bridge the form key into
     * the request's param bag via the RequestInterface::setParams() method
     * every request object provides, then hand off to the real validator —
     * so the actual comparison (timing-safe, via Security::compareStrings)
     * is Magento's own code, not a reimplementation of it here.
     *
     * Returning true unconditionally — the obvious shortcut — would leave
     * the shopper's quote mutable from any page on the internet; the
     * widget already carries the form key at
     * window.__IDEA89_CHECKOUT.formKey, so there's no reason to skip this.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $body = $this->decodedBody();
        $formKey = $this->stringField($body, 'form_key');
        $request->setParams(['form_key' => $formKey]);
        return $this->formKeyValidator->validate($request);
    }

    /**
     * Compare the request's Origin header against the storefront base URL.
     *
     * DEVIATION FROM Controller/Customer/Me.php's isSameOrigin(), which this
     * was originally copied verbatim from: Me.php is a GET-only precedent
     * and its leniency on a MISSING Origin header (treat it as same-origin)
     * is correct there — a top-level or non-fetch GET can legitimately
     * arrive without one. On a state-changing POST route, browsers reliably
     * attach Origin (same-origin fetch()es included, in every major engine
     * since ~2020), so a missing Origin here is itself suspicious rather
     * than an ordinary gap to tolerate, and costs nothing to reject — real
     * clients (the widget, any browser) always send it on a POST. Rejecting
     * closes a gap that would otherwise rely solely on the form-key check
     * for defence.
     */
    private function isSameOrigin(): bool
    {
        $origin = (string) ($this->request->getHeader('Origin') ?: '');
        if ($origin === '') {
            return false;
        }
        $baseUrl = (string) $this->storeManager->getStore()->getBaseUrl();
        $originHost = parse_url($origin, PHP_URL_HOST);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        return $originHost !== null && $originHost === $baseHost;
    }

    private function sessionId(): string
    {
        return (string) $this->checkoutSession->getSessionId();
    }

    /**
     * DEVIATION FROM ROUND 1 (fixed in round 2, see the task report):
     * $this->request->getClientIp() defaults to $checkProxy = true, which
     * trusts HTTP_CLIENT_IP / HTTP_X_FORWARDED_FOR verbatim from ANY
     * client — no trusted-proxy restriction — so a caller could set a
     * fresh X-Forwarded-For per request and land in a fresh IP bucket
     * every time, defeating the whole point of this bucket. RemoteAddress
     * is injected instead: same underlying header, but it validates each
     * candidate as a real IP and — when the store's trusted proxies are
     * configured — filters out the proxy hops to find the real client
     * address. IMPORTANT: with no trusted proxies configured (Magento's
     * shipped default), X-Forwarded-For is still attacker-controlled and
     * this bucket is friction, not a boundary — see
     * Model/CheckoutRateLimit.php's docblock. Closing it properly is a
     * deploy-time step: put a proxy in front that overwrites
     * X-Forwarded-For with the real peer address.
     */
    private function clientIp(): string
    {
        return (string) $this->remoteAddress->getRemoteAddress();
    }

    /**
     * @return array<string,mixed>
     */
    private function decodedBody(): array
    {
        $decoded = json_decode((string) $this->request->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A JSON body can carry any type under a given key — an array, a bool,
     * a number — not just a string. Casting one of those with (string)
     * either raises a PHP warning (arrays; fatal under this suite's
     * failOnWarning=true) or silently coerces (numbers/bools) into a value
     * that was never a real code and is safely rejected downstream anyway.
     * isset() already excludes an explicit JSON null; this excludes
     * everything else that isn't actually a string.
     *
     * @param array<string,mixed> $body
     */
    private function stringField(array $body, string $key): string
    {
        return isset($body[$key]) && is_string($body[$key]) ? $body[$key] : '';
    }
}
