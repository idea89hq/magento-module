<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Acp;

use Idea89\Assistant\Model\Acp\Auth;
use Idea89\Assistant\Model\CheckoutConfig;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive coverage of every branch in task-4.2-brief.md, including the
 * two that are easy to conflate: a blank configured secret means the
 * bearer check is skipped entirely (the endpoint is public on purpose),
 * and a present-but-empty Authorization header must never be treated as
 * satisfying a configured secret.
 */
class AuthTest extends TestCase
{
    /** @var CheckoutConfig&MockObject */
    private $checkoutConfig;

    /** @var Http&MockObject */
    private $request;

    private Auth $auth;

    protected function setUp(): void
    {
        $this->checkoutConfig = $this->createMock(CheckoutConfig::class);
        $this->request        = $this->createMock(Http::class);
        $this->auth           = new Auth($this->checkoutConfig);

        // Every test in this file exercises an ACP-enabled store unless it
        // is specifically the not_enabled test — that is the one branch
        // that must win regardless of everything else.
        $this->checkoutConfig->method('isAcpEnabled')->willReturn(true);
    }

    private function stubHeaders(?string $authorization, ?string $apiVersion = null): void
    {
        $this->request->method('getHeader')->willReturnCallback(
            static function (string $name) use ($authorization, $apiVersion) {
                return match ($name) {
                    'Authorization' => $authorization ?? false,
                    'API-Version'   => $apiVersion ?? false,
                    default         => false,
                };
            }
        );
    }

    public function testNoSecretAndNoHeaderIsAuthorised(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('');
        $this->stubHeaders(null);

        $this->assertNull($this->auth->check($this->request));
    }

    public function testSecretSetWithCorrectBearerIsAuthorised(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('sk_live_abc123');
        $this->stubHeaders('Bearer sk_live_abc123');

        $this->assertNull($this->auth->check($this->request));
    }

    public function testSecretSetWithWrongBearerIsUnauthorized(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('sk_live_abc123');
        $this->stubHeaders('Bearer someone-elses-token');

        $this->assertSame('unauthorized', $this->auth->check($this->request));
    }

    public function testSecretSetWithNoHeaderAtAllIsUnauthorized(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('sk_live_abc123');
        $this->stubHeaders(null);

        $this->assertSame('unauthorized', $this->auth->check($this->request));
    }

    /**
     * The header is PRESENT but empty ("Authorization: "), as distinct
     * from being absent entirely. Both must fail, but they are different
     * code paths (getHeader() returns "" vs false) and it is easy to
     * accidentally special-case only one of them.
     */
    public function testSecretSetWithPresentButEmptyAuthorizationHeaderIsUnauthorized(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('sk_live_abc123');
        $this->stubHeaders('');

        $this->assertSame('unauthorized', $this->auth->check($this->request));
    }

    /** The "Bearer " prefix with nothing after it must not compare as a match. */
    public function testSecretSetWithBearerPrefixAndEmptyTokenIsUnauthorized(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('sk_live_abc123');
        $this->stubHeaders('Bearer ');

        $this->assertSame('unauthorized', $this->auth->check($this->request));
    }

    /**
     * A blank configured secret means the endpoint is public BY DESIGN —
     * the bearer check must be skipped entirely, not merely "an empty
     * token happens to equal an empty secret". Proven here by sending a
     * garbage Authorization header that would fail any real comparison;
     * if the check were not truly skipped this would wrongly return
     * unauthorized instead of null.
     */
    public function testBlankConfiguredSecretSkipsTheBearerCheckEntirely(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('');
        $this->stubHeaders('Bearer some-garbage-value-that-matches-nothing');

        $this->assertNull($this->auth->check($this->request));
    }

    public function testKnownApiVersionIsAuthorised(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('');
        $this->stubHeaders(null, '2026-04-17');

        $this->assertNull($this->auth->check($this->request));
    }

    public function testAbsentApiVersionIsAuthorisedAndMeansCurrent(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('');
        $this->stubHeaders(null, null);

        $this->assertNull($this->auth->check($this->request));
    }

    /**
     * Pins the deliberate asymmetry with checkBearer(): a present-but-empty
     * Authorization header is a hard failure (its own named test above),
     * but a present-but-empty API-Version header degrades to "absent" the
     * same as a missing one, because it only ever widens to "accept the
     * current version" rather than granting access.
     */
    public function testPresentButEmptyApiVersionIsAuthorisedAndMeansCurrent(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('');
        $this->stubHeaders(null, '');

        $this->assertNull($this->auth->check($this->request));
    }

    public function testUnknownApiVersionIsUnsupported(): void
    {
        $this->checkoutConfig->method('getAcpSecret')->willReturn('');
        $this->stubHeaders(null, '2020-01-01');

        $this->assertSame('unsupported_api_version', $this->auth->check($this->request));
    }

    public function testAcpDisabledIsNotEnabledEvenWithACorrectBearerAndKnownVersion(): void
    {
        $this->checkoutConfig = $this->createMock(CheckoutConfig::class);
        $this->checkoutConfig->method('isAcpEnabled')->willReturn(false);
        // not_enabled must win before the secret is even consulted.
        $this->checkoutConfig->expects($this->never())->method('getAcpSecret');
        $this->auth = new Auth($this->checkoutConfig);

        $this->stubHeaders('Bearer sk_live_abc123', '2026-04-17');

        $this->assertSame('not_enabled', $this->auth->check($this->request));
    }
}
