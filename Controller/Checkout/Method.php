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
 * POST /idea89/checkout/method
 * Body: {carrier, method, form_key}
 *
 * Same shape as Context.php and Place.php — see Place.php's docblock for
 * why validateForCsrf() decodes the JSON body itself rather than relying
 * on $request->getParam(). Rate limit uses the shared checkOther() bucket
 * (30/session/10min), same as Context and Address — only Place gets the
 * tighter checkPlace() ceiling, because only Place spends money.
 */
class Method implements HttpPostActionInterface, CsrfAwareActionInterface
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
        $gate = $this->rateLimit->checkOther($this->sessionId(), $this->clientIp());
        if (!$gate['allowed']) {
            $result->setHeader('Retry-After', (string) $gate['retry_after'], true);
            return $result->setHttpResponseCode(429)
                ->setData(['error' => 'rate_limited', 'retry_after' => $gate['retry_after']]);
        }

        // 4. Delegate. The facade is the only thing that touches a quote.
        $body = $this->decodedBody();
        $carrier = $this->stringField($body, 'carrier');
        $method = $this->stringField($body, 'method');

        try {
            return $result->setData($this->facade->setShippingMethod($carrier, $method));
        } catch (LocalizedException $e) {
            // Expected shopper-facing outcome, not a fault — not logged as
            // an error. Facade's own contract: every message here is
            // written to be shown to the shopper verbatim.
            return $result->setHttpResponseCode(400)
                ->setData(['error' => 'checkout_error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('[idea89] native checkout method failed', ['exception' => $e]);
            return $result->setHttpResponseCode(500)
                ->setData(['error' => 'internal_error']);
        }
    }

    /**
     * DEVIATION FROM THE BRIEF (verified on the wire against Place.php's
     * identical guard — see Controller/Checkout/Place.php's docblock and
     * the task report): returning null here does NOT yield a 403. Magento's
     * own CsrfValidator::createException() falls back to a 302 redirect on
     * a concrete false from validateForCsrf(), which breaks the widget's
     * JSON parsing and this module's error-shape contract. We build the
     * 403 JSON response ourselves instead.
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
     * Validate the Magento form key carried in the JSON body — see
     * Place::validateForCsrf() for the full reasoning. Same mechanism here:
     * bridge the body's form_key into the request's param bag, then let
     * the real Magento\Framework\Data\Form\FormKey\Validator do the
     * timing-safe comparison.
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
     * DEVIATION FROM Controller/Customer/Me.php's isSameOrigin() — see
     * Place.php's isSameOrigin() docblock for the full reasoning. Summary:
     * Me.php's leniency on a missing Origin is correct for a GET-only
     * precedent; on this state-changing POST route, browsers reliably
     * attach Origin, so a missing one is rejected rather than tolerated.
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
     * is injected instead — see Place.php's clientIp() docblock for the
     * full reasoning and the trust-boundary caveat that still applies
     * without a merchant configuring trusted proxies.
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
     * See Place::stringField() for why: a JSON body can carry any type
     * under a key, and (string)-casting an array raises a PHP warning
     * (fatal under this suite's failOnWarning=true).
     *
     * @param array<string,mixed> $body
     */
    private function stringField(array $body, string $key): string
    {
        return isset($body[$key]) && is_string($body[$key]) ? $body[$key] : '';
    }
}
