<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Customer;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Idea89\Assistant\Model\OrderTrackingConfig;
use Idea89\Assistant\Model\PersonalizationConfig;

/**
 * GET /idea89/customer/me
 *
 * Pattern A privacy entry-point. The chat widget calls this same-origin
 * to discover whether the current shopper is logged in. We return the
 * minimum the widget needs to decide between the logged-in flow (fetch
 * recent orders silently) and the guest flow (show the email + order#
 * form). Notably we do NOT return the full email — only an 8-char hash,
 * so the response is useless to an attacker who somehow obtains it.
 *
 * Always returns 200 with a small JSON body; never 401, since "are you
 * logged in?" is a public question. Origin guard rejects cross-origin
 * fetches as defence in depth alongside the browser's same-origin
 * cookie policy.
 */
class Me implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderTrackingConfig $config,
        private readonly RequestInterface $request,
        private readonly PersonalizationConfig $personalization
    ) {}

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);

        // Origin guard — only same-origin calls (browser fetch with
        // credentials:'include' from the merchant's storefront). Defence
        // in depth; cookie attachment is already restricted by browser
        // policy, this just catches misconfigured downstream proxies.
        if (!$this->isSameOrigin()) {
            return $result->setHttpResponseCode(403)
                ->setData(['error' => 'cross_origin_forbidden']);
        }

        // Resolve the shopper's session state unconditionally — both the
        // order-tracking response and the identity token need it regardless
        // of which features are enabled.
        $isLoggedIn = $this->customerSession->isLoggedIn();

        // Mint the identity token when personalization is enabled and both
        // secrets are configured. Gated only on personalization — independent
        // of order tracking so stores with personalization ON but order
        // tracking OFF still receive a token. Token is null when
        // personalization is off or credentials are missing — widget treats
        // null as no identity.
        $secret = $this->personalization->getSigningSecret();
        $apiKey = $this->personalization->getApiKey();
        $token = null;
        if ($this->personalization->isEnabled() && $secret !== '' && $apiKey !== '') {
            $now = time();
            $payload = [
                'magento_customer_id' => $isLoggedIn ? (string) $this->customerSession->getCustomerId() : null,
                'customer_group_id'   => (int) $this->customerSession->getCustomerGroupId(),
                'is_logged_in'        => $isLoggedIn,
                'store_ref'           => $apiKey,
                'iat'                 => $now,
                'exp'                 => $now + 3600,
            ];
            $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
            $macB64 = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');
            $token = $payloadB64 . '.' . $macB64;
        }

        // Master toggle — when order tracking is disabled the widget
        // shouldn't ask for order details. We still respond 200 so the
        // widget can gracefully fall back, and include the identity token
        // so personalization remains functional even when order tracking
        // is off.
        if (!$this->config->isEnabled()) {
            return $result->setData([
                'logged_in'      => false,
                'feature_enabled' => false,
                'identity_token' => $token,
            ]);
        }

        if (!$isLoggedIn) {
            return $result->setData([
                'logged_in'       => false,
                'feature_enabled' => true,
                'identity_token'  => $token,
            ]);
        }

        $customer = $this->customerSession->getCustomer();
        $email = (string) $customer->getEmail();
        $firstName = (string) $customer->getFirstname();

        return $result->setData([
            'logged_in' => true,
            'feature_enabled' => true,
            // First name is fine to return — it's already plastered all
            // over the customer's account area in plain text and is the
            // minimum needed for a friendly greeting in the chat.
            'first_name' => $firstName,
            // Email is hashed — used only for client-side analytics dedup,
            // never displayed. Truncate to 8 chars (still uniquely IDs
            // within any one customer's session).
            'email_hash'     => substr(hash('sha256', strtolower($email)), 0, 8),
            'identity_token' => $token,
        ]);
    }

    /**
     * Compare the request's Origin header against the storefront base
     * URL. Matches host+port+scheme; rejects null/missing Origin only
     * when the request method requires it (GET preflight-less calls
     * sometimes omit Origin, so we're lenient there).
     */
    private function isSameOrigin(): bool
    {
        $origin = (string) ($this->request->getHeader('Origin') ?: '');
        if ($origin === '') {
            // No Origin header at all — typical for top-level GETs.
            // The same-origin policy already prevented a cross-origin
            // browser fetch from being made without an Origin, so this
            // is safe; cURL etc. can hit this but they have no session
            // cookie to spoof anyway.
            return true;
        }
        $baseUrl = (string) $this->storeManager->getStore()->getBaseUrl();
        $originHost = parse_url($origin, PHP_URL_HOST);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        return $originHost !== null && $originHost === $baseHost;
    }
}
