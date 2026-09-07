<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Controller\Acp;

use Idea89\Assistant\Controller\Acp\CheckoutSessions;
use Idea89\Assistant\Model\Acp\Auth;
use Idea89\Assistant\Model\Acp\Delegate;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * task-4.4-brief.md's two behaviours, both gated by Auth::check() FIRST —
 * proven here by asserting Delegate is never even consulted when auth
 * fails, which is what actually keeps an unauthorised caller from
 * learning which of the two states (handler installed / not installed)
 * the store is in.
 */
class CheckoutSessionsTest extends TestCase
{
    private const BASE_URL = 'https://app.magento2.test/';

    /**
     * @param array<string,mixed> $jsonCapture Filled by reference with
     *        'data', 'code', 'headers' once execute() runs.
     */
    private function build(
        ?string $authError,
        bool $moduleInstalled,
        string $originalPathInfo,
        array &$jsonCapture
    ): CheckoutSessions {
        $jsonCapture = ['data' => null, 'code' => 200, 'headers' => []];

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json, &$jsonCapture) {
            $jsonCapture['data'] = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(
            function (int $code) use ($json, &$jsonCapture) {
                $jsonCapture['code'] = $code;
                return $json;
            }
        );
        $json->method('setHeader')->willReturnCallback(
            function (string $name, $value) use ($json, &$jsonCapture) {
                $jsonCapture['headers'][$name] = $value;
                return $json;
            }
        );
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $request = $this->createMock(Http::class);
        $request->method('getOriginalPathInfo')->willReturn($originalPathInfo);

        $auth = $this->createMock(Auth::class);
        $auth->method('check')->willReturn($authError);

        $delegate = $this->createMock(Delegate::class);
        if ($authError !== null) {
            // The whole point of auth-first: a failed check must never
            // even ask whether a handler is installed.
            $delegate->expects($this->never())->method('isTransactionalModuleInstalled');
        } else {
            $delegate->method('isTransactionalModuleInstalled')->willReturn($moduleInstalled);
        }

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn(self::BASE_URL);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CheckoutSessions($jsonFactory, $request, $auth, $delegate, $storeManager);
    }

    public function testNotEnabledDeclinesWithAuthErrorAndNeverConsultsDelegate(): void
    {
        $capture = [];
        $controller = $this->build('not_enabled', true, 'idea89/acp/checkout_sessions', $capture);

        $controller->execute();

        $this->assertSame(404, $capture['code']);
        $this->assertSame(['error' => 'not_enabled'], $capture['data']);
    }

    public function testUnauthorizedReturns401AndBodyRevealsNothingAboutDelegateState(): void
    {
        $capture = [];
        $controller = $this->build('unauthorized', true, 'idea89/acp/checkout_sessions', $capture);

        $controller->execute();

        $this->assertSame(401, $capture['code']);
        $this->assertSame(['error' => 'unauthorized'], $capture['data']);
    }

    public function testUnsupportedApiVersionReturns400(): void
    {
        $capture = [];
        $controller = $this->build('unsupported_api_version', false, 'idea89/acp/checkout_sessions', $capture);

        $controller->execute();

        $this->assertSame(400, $capture['code']);
        $this->assertSame(['error' => 'unsupported_api_version'], $capture['data']);
    }

    public function testInstalledModuleRedirectsWith307AndLocationHeaderForTheBaseRoute(): void
    {
        $capture = [];
        $controller = $this->build(null, true, 'idea89/acp/checkout_sessions', $capture);

        $controller->execute();

        $this->assertSame(307, $capture['code']);
        $this->assertSame(
            'https://app.magento2.test/agentic_commerce/checkout_sessions',
            $capture['headers']['Location'] ?? null
        );
    }

    #[DataProvider('suffixProvider')]
    public function testInstalledModuleRedirectsPreservingTheIdAndSubActionSuffix(
        string $originalPath,
        string $expectedLocation
    ): void {
        $capture = [];
        $controller = $this->build(null, true, $originalPath, $capture);

        $controller->execute();

        $this->assertSame(307, $capture['code']);
        $this->assertSame($expectedLocation, $capture['headers']['Location'] ?? null);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function suffixProvider(): array
    {
        return [
            // Covers both the GET-retrieve and POST-update routes — the
            // controller does not branch on method, so both land at the
            // same target path.
            'by id (GET retrieve / POST update)' => [
                'idea89/acp/checkout_sessions/cs_abc123',
                'https://app.magento2.test/agentic_commerce/checkout_sessions/cs_abc123',
            ],
            'complete' => [
                'idea89/acp/checkout_sessions/cs_abc123/complete',
                'https://app.magento2.test/agentic_commerce/checkout_sessions/cs_abc123/complete',
            ],
            'cancel' => [
                'idea89/acp/checkout_sessions/cs_abc123/cancel',
                'https://app.magento2.test/agentic_commerce/checkout_sessions/cs_abc123/cancel',
            ],
        ];
    }

    public function testNotInstalledReturns501WithTheDocumentedDeclineBody(): void
    {
        $capture = [];
        $controller = $this->build(null, false, 'idea89/acp/checkout_sessions', $capture);

        $controller->execute();

        $this->assertSame(501, $capture['code']);
        $this->assertSame(
            [
                'type' => 'not_implemented',
                'code' => 'agentic_checkout_unavailable',
                'message' => 'This store publishes an ACP product feed but does not accept '
                    . 'agent-initiated checkout. See https://idea89.com/docs/agentic-commerce',
            ],
            $capture['data']
        );
    }

    public function testEveryResponseIsNoStore(): void
    {
        foreach ([
            ['not_enabled', true],
            [null, true],
            [null, false],
        ] as [$authError, $installed]) {
            $capture = [];
            $controller = $this->build($authError, $installed, 'idea89/acp/checkout_sessions', $capture);
            $controller->execute();
            $this->assertSame('no-store', $capture['headers']['Cache-Control'] ?? null);
        }
    }
}
