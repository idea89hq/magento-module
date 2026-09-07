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
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /idea89/checkout/context
 *
 * Read-only snapshot of the shopper's own quote — item count, totals,
 * allowed payment methods, whether shipping applies — so the widget can
 * render the first step of Tier 3 (native) checkout. No CsrfAwareActionInterface
 * here: Magento's CSRF plugin only gates state-changing requests, and a GET
 * has nothing to mutate; the same-origin + credentialed-cookie combination
 * below is the correct (and sufficient) guard, exactly as Controller/Customer/
 * Me.php — this module's other same-origin GET endpoint — already does it.
 *
 * This is the reference shape for the other three Checkout controllers
 * (Address, Method, Place): same-origin guard, then feature gate (404, not
 * 403 — an unused route should not advertise its own existence), then rate
 * limit, then delegate to Model/Checkout/Facade.php, which is the only
 * thing in this module allowed to touch the quote.
 */
class Context implements HttpGetActionInterface
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
        // trivially defeated by a client that discards its session cookie
        // and mints a fresh one, since Magento hands out a valid session +
        // form key on any ordinary GET).
        $gate = $this->rateLimit->checkOther($this->sessionId(), $this->clientIp());
        if (!$gate['allowed']) {
            $result->setHeader('Retry-After', (string) $gate['retry_after'], true);
            return $result->setHttpResponseCode(429)
                ->setData(['error' => 'rate_limited', 'retry_after' => $gate['retry_after']]);
        }

        // 4. Delegate. The facade is the only thing that touches a quote.
        try {
            return $result->setData($this->facade->context());
        } catch (LocalizedException $e) {
            // Expected shopper-facing outcome, not a fault — not logged as
            // an error. Facade's own contract: every message here is
            // written to be shown to the shopper verbatim.
            return $result->setHttpResponseCode(400)
                ->setData(['error' => 'checkout_error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('[idea89] native checkout context failed', ['exception' => $e]);
            return $result->setHttpResponseCode(500)
                ->setData(['error' => 'internal_error']);
        }
    }

    /**
     * Compare the request's Origin header against the storefront base URL.
     * Copied verbatim from Controller/Customer/Me.php::isSameOrigin() —
     * see that file for the reasoning (lenient on a missing Origin header;
     * the browser's same-origin policy already gated the credentialed
     * fetch that would carry one).
     */
    private function isSameOrigin(): bool
    {
        $origin = (string) ($this->request->getHeader('Origin') ?: '');
        if ($origin === '') {
            return true;
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
}
