<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Orders;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Idea89\Assistant\Model\OrderSanitizer;
use Idea89\Assistant\Model\OrderTrackingConfig;
use Idea89\Assistant\Model\GuestLookupRateLimit;
use Psr\Log\LoggerInterface;

/**
 * POST /idea89/orders/lookup
 *
 * Guest order lookup. Body: {"increment_id":"100023","email":"shopper@example.com"}.
 *
 * Authentication: a (email, increment_id) pair is BOTH the identifier
 * AND the credential — same pattern Magento's own Order & Returns page
 * uses. Email is matched against the order's customer_email; mismatch
 * returns the SAME 404 response as "no such increment_id" to prevent
 * enumeration.
 *
 * Rate limit: 4 attempts/IP/hour via GuestLookupRateLimit; 429 with a
 * retry_after header when exhausted. The 429 response IS distinguishable
 * from the 404 response (different status code) — acceptable because
 * the cap protects against brute force on the order_number space, which
 * a 404 mask wouldn't.
 *
 * CSRF: we implement CsrfAwareActionInterface and accept the request
 * without a form-key because the email+increment_id pair already gates
 * access. A malicious cross-origin POST cannot succeed without knowing
 * a valid pair, which it can't enumerate (see rate limit).
 *
 * Logging: increment_id is logged (helps debug), email is NEVER logged
 * (PII minimisation).
 */
class Lookup implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Soft email regex — Magento allows long, internationalised local
     * parts but we only need it to weed out obviously-bad inputs before
     * hitting the DB. We don't validate strictly; the DB equality check
     * with `customer_email` is the canonical match.
     */
    private const EMAIL_RE = '/^[^@\s]{1,128}@[^@\s]{1,256}\.[A-Za-z]{2,24}$/';

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderSanitizer $sanitizer,
        private readonly OrderTrackingConfig $config,
        private readonly GuestLookupRateLimit $rateLimit,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // accept; auth is via the email + increment_id pair
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);

        // Origin guard
        $origin = (string) ($this->request->getHeader('Origin') ?: '');
        if ($origin !== '') {
            $baseHost = parse_url((string) $this->storeManager->getStore()->getBaseUrl(), PHP_URL_HOST);
            $originHost = parse_url($origin, PHP_URL_HOST);
            if ($originHost !== $baseHost) {
                return $result->setHttpResponseCode(403)
                    ->setData(['error' => 'cross_origin_forbidden']);
            }
        }

        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'feature_disabled']);
        }

        // Rate-limit BEFORE parsing input so an enumeration attacker
        // pays the cost on every attempt regardless of how malformed
        // their payloads are.
        $ip = (string) $this->request->getClientIp();
        $gate = $this->rateLimit->check($ip);
        if (!$gate['allowed']) {
            $result->setHeader('Retry-After', (string) $gate['retry_after'], true);
            return $result->setHttpResponseCode(429)
                ->setData(['error' => 'rate_limited', 'retry_after' => $gate['retry_after']]);
        }

        // Body parse
        $raw = (string) $this->request->getContent();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return $result->setHttpResponseCode(400)
                ->setData(['error' => 'invalid_body']);
        }

        $incrementId = isset($body['increment_id']) ? (string) $body['increment_id'] : '';
        $email = isset($body['email']) ? (string) $body['email'] : '';

        // Coarse input validation. Strictly we could let the DB equality
        // be the only check, but bouncing obvious garbage early keeps the
        // logs cleaner and the cache hit-rate up.
        if ($incrementId === '' || strlen($incrementId) > 32) {
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'order_not_found']);
        }
        if (!preg_match(self::EMAIL_RE, $email)) {
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'order_not_found']);
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $criteria = $this->criteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->addFilter('store_id', $storeId)
            ->setPageSize(1)
            ->create();

        try {
            $list = $this->orderRepository->getList($criteria);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)
                ->setData(['error' => 'lookup_failed']);
        }

        $items = $list->getItems();
        if (empty($items)) {
            $this->logger->info('[idea89-orders-lookup] not_found', ['increment_id' => $incrementId]);
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'order_not_found']);
        }
        $order = reset($items);

        // Compare emails case-insensitively. If they don't match, we
        // return the same 404 — never reveal that the order exists.
        $storedEmail = strtolower((string) $order->getCustomerEmail());
        if ($storedEmail !== strtolower($email)) {
            $this->logger->info('[idea89-orders-lookup] email_mismatch', ['increment_id' => $incrementId]);
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'order_not_found']);
        }

        // Successful match — clear the rate-limit bucket so this user
        // can keep tracking other orders without bumping into the cap
        // because of earlier typos.
        $this->rateLimit->reset($ip);
        $this->logger->info('[idea89-orders-lookup] match', ['increment_id' => $incrementId]);
        return $result->setData([
            'order' => $this->sanitizer->sanitize($order, detail: true),
        ]);
    }
}
