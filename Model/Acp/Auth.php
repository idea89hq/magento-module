<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Acp;

use Idea89\Assistant\Model\CheckoutConfig;
use Magento\Framework\App\Request\Http;

/**
 * Bearer-token and API-Version gate for every ACP-facing route (Task 4.3's
 * product feed, Task 4.4's checkout-session endpoints). check() returns an
 * error code, or null when the request is authorised — callers map the
 * code straight onto the ACP-shaped error response. Never throws.
 *
 * Checks run in this fixed order, per task-4.2-brief.md:
 *   1. not_enabled  — a disabled store refuses regardless of what else is
 *                      on the request; the secret is never even read.
 *   2. unauthorized — the bearer token, unless the configured secret is
 *                      blank, in which case the check is skipped entirely
 *                      (the merchant chose to serve this publicly).
 *   3. unsupported_api_version — the API-Version pin, only reached once
 *                      the request has already cleared the bearer check.
 *
 * Type-hinted against the concrete \Magento\Framework\App\Request\Http
 * rather than RequestInterface: neither \Magento\Framework\App\RequestInterface
 * nor \Magento\Framework\App\HttpRequestInterface declares getHeader() —
 * confirmed against vendor/magento/framework. Magento core has the same
 * gap (Magento\StoreGraphQl\Controller\HttpRequestValidator\StoreValidator
 * calls getHeader() on HttpRequestInterface without it being declared
 * there); Http is what every Magento HTTP controller actually receives at
 * runtime from getRequest(), and unlike either interface it can be mocked
 * in a unit test.
 */
class Auth
{
    /**
     * API-Version values this module has verified its ACP surface against.
     * The protocol is still beta; an unrecognised version is refused
     * rather than served against a schema we have not checked.
     */
    public const SUPPORTED_API_VERSIONS = ['2026-04-17'];

    private const ERROR_NOT_ENABLED            = 'not_enabled';
    private const ERROR_UNAUTHORIZED           = 'unauthorized';
    private const ERROR_UNSUPPORTED_API_VERSION = 'unsupported_api_version';

    public function __construct(private readonly CheckoutConfig $checkoutConfig)
    {
    }

    public function check(Http $request): ?string
    {
        if (!$this->checkoutConfig->isAcpEnabled()) {
            return self::ERROR_NOT_ENABLED;
        }

        $bearerError = $this->checkBearer($request);
        if ($bearerError !== null) {
            return $bearerError;
        }

        return $this->checkApiVersion($request);
    }

    private function checkBearer(Http $request): ?string
    {
        $secret = $this->checkoutConfig->getAcpSecret();
        if ($secret === '') {
            // The merchant left the token blank on purpose (system.xml says
            // so explicitly) — that means "serve this publicly", a
            // different behaviour from "accept anything". Skip the bearer
            // check entirely rather than comparing against an empty string.
            return null;
        }

        $header = $request->getHeader('Authorization');
        $token = ($header !== false && str_starts_with($header, 'Bearer '))
            ? substr($header, 7)
            : '';

        // hash_equals(), never ===: this is a shared secret, and === leaks
        // timing information proportional to the length of the matching
        // prefix. An empty $token (header absent, present-but-empty, or
        // missing the "Bearer " prefix) can never satisfy a non-empty
        // $secret here — $secret is guaranteed non-empty at this point.
        return hash_equals($secret, $token) ? null : self::ERROR_UNAUTHORIZED;
    }

    private function checkApiVersion(Http $request): ?string
    {
        $version = $request->getHeader('API-Version');
        if ($version === false || $version === '') {
            // Absent is allowed and means "current" — the spec is beta and
            // pinning is how a beta consumer protects itself, not a
            // requirement we impose on every caller.
            //
            // Deliberately treating "" the same as false here, unlike
            // checkBearer()'s present-but-empty Authorization header (which
            // is a hard failure): an empty version pin cannot be exploited
            // the way an empty token could be, since it only ever widens
            // to "accept the current version" rather than granting access.
            return null;
        }

        return in_array($version, self::SUPPORTED_API_VERSIONS, true)
            ? null
            : self::ERROR_UNSUPPORTED_API_VERSION;
    }
}
