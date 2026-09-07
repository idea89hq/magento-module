<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Controller\Checkout;

use Idea89\Assistant\Controller\Checkout\Place;
use Idea89\Assistant\Model\Checkout\Facade;
use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\CheckoutRateLimit;
use Idea89\Assistant\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The seven cases the brief requires, all proving that a guard failure
 * both short-circuits with the right status AND never reaches the façade —
 * a status code alone doesn't prove the quote was left untouched.
 */
class PlaceTest extends TestCase
{
    /**
     * A valid, matching Origin — isSameOrigin() now REJECTS a missing
     * Origin on this POST route (tightened post-review; see
     * Place::isSameOrigin()'s docblock), so every test that means to reach
     * past guard 1 must supply one, unlike the GET-only leniency Context.php
     * still has.
     */
    private const SAME_ORIGIN = 'https://app.magento2.test';

    /**
     * @param array<string,mixed> $facade Optional overrides: 'result' (array
     *        to return from place()), or 'throw' (a \Throwable to throw).
     */
    private function build(
        ?string $originHeader,
        bool $moduleEnabled,
        string $mode,
        bool $rateLimitAllowed,
        array $facade = [],
        ?string $rawBody = null,
        string $remoteIp = '203.0.113.5'
    ): array {
        $jsonData = null;
        $jsonCode = 200;
        $jsonHeaders = [];
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json, &$jsonData) {
            $jsonData = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($json, &$jsonCode) {
            $jsonCode = $code;
            return $json;
        });
        $json->method('setHeader')->willReturnCallback(function (string $name, $value) use ($json, &$jsonHeaders) {
            $jsonHeaders[$name] = $value;
            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(
            fn (string $name) => $name === 'Origin' ? $originHeader : false
        );
        $request->method('getContent')->willReturn(
            $rawBody ?? (string) json_encode(['payment_method' => 'checkmo'])
        );

        // Round 2 fix: the controller no longer sources the client IP from
        // $request->getClientIp() at all (that defaulted to trusting a
        // caller-supplied X-Forwarded-For with no proxy restriction) — it's
        // RemoteAddress::getRemoteAddress() now. $remoteIp defaults to
        // TEST-NET-3 (RFC 5737, reserved for documentation, never a real
        // shopper's address); tests that need to prove two different
        // addresses are treated as two different callers pass a different
        // value.
        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn($remoteIp);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://app.magento2.test/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getSessionId')->willReturn('sess-abc123');

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn($moduleEnabled);

        $checkoutConfig = $this->createMock(CheckoutConfig::class);
        $checkoutConfig->method('isNative')->willReturn($mode === CheckoutConfig::MODE_NATIVE);

        // Captured rather than asserted via expects()->with(), same
        // reasoning as the Json result mocks above: lets a test inspect
        // exactly what IP/session checkPlace() was invoked with (needed to
        // prove the IP bucket is keyed off RemoteAddress) without a second,
        // conflicting stub configuration on the same mocked method.
        $rateLimitCalls = [];
        $rateLimit = $this->createMock(CheckoutRateLimit::class);
        $rateLimit->method('checkPlace')->willReturnCallback(
            function (string $sessionId, string $ip) use (&$rateLimitCalls, $rateLimitAllowed) {
                $rateLimitCalls[] = ['sessionId' => $sessionId, 'ip' => $ip];
                return [
                    'allowed' => $rateLimitAllowed,
                    'attempts' => $rateLimitAllowed ? 1 : 9,
                    'retry_after' => $rateLimitAllowed ? 0 : 300,
                ];
            }
        );

        $facadeMock = $this->createMock(Facade::class);
        if (isset($facade['throw'])) {
            $facadeMock->method('place')->willThrowException($facade['throw']);
        } elseif ($facade['skipDefaultStub'] ?? false) {
            // Caller configures place() itself after build() returns — used
            // when the test needs to assert on the exact argument place()
            // received, which a canned willReturn() here would collide with
            // (PHPUnit does not cleanly support two independent stubs for
            // the same method on the same mock).
        } else {
            $facadeMock->method('place')->willReturn($facade['result'] ?? [
                'order_id' => '100000123',
                'total' => '49.99',
                'currency' => 'GBP',
            ]);
        }

        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('the-real-form-key');
        $formKeyValidator = new FormKeyValidator($formKey);

        $logger = $this->createMock(LoggerInterface::class);

        $controller = new Place(
            $jsonFactory,
            $request,
            $storeManager,
            $checkoutSession,
            $config,
            $checkoutConfig,
            $rateLimit,
            $facadeMock,
            $formKeyValidator,
            $logger,
            $remoteAddress
        );

        return [
            'controller' => $controller,
            'facade' => $facadeMock,
            'logger' => $logger,
            'rateLimit' => $rateLimit,
            'rateLimitCalls' => &$rateLimitCalls,
            'code' => &$jsonCode,
            'data' => &$jsonData,
            'headers' => &$jsonHeaders,
            'request' => $request,
        ];
    }

    // --- 1. Cross-origin ---------------------------------------------

    public function testCrossOriginRequestReturns403AndNeverCallsTheFacade(): void
    {
        $b = $this->build('https://evil.example', true, CheckoutConfig::MODE_NATIVE, true);
        $b['facade']->expects($this->never())->method('place');

        $b['controller']->execute();

        $this->assertSame(403, $b['code']);
        $this->assertSame('cross_origin_forbidden', $b['data']['error']);
    }

    /**
     * Minor fix from the review round: isSameOrigin() now REJECTS a missing
     * Origin on this POST route rather than treating it as same-origin (the
     * GET-only leniency Context.php still has, copied from Me.php) —
     * browsers reliably attach Origin to a POST, so a missing one is itself
     * suspicious here, not an ordinary gap to tolerate.
     */
    public function testMissingOriginOnAPostRouteReturns403AndNeverCallsTheFacade(): void
    {
        $b = $this->build(null, true, CheckoutConfig::MODE_NATIVE, true);
        $b['facade']->expects($this->never())->method('place');

        $b['controller']->execute();

        $this->assertSame(403, $b['code']);
        $this->assertSame('cross_origin_forbidden', $b['data']['error']);
    }

    // --- 2. Mode != native ---------------------------------------------

    public function testModeNotNativeReturns404AndNeverCallsTheFacade(): void
    {
        $b = $this->build(self::SAME_ORIGIN, true, CheckoutConfig::MODE_EXPRESS, true);
        $b['facade']->expects($this->never())->method('place');

        $b['controller']->execute();

        $this->assertSame(404, $b['code']);
        $this->assertSame('not_available', $b['data']['error']);
    }

    public function testModuleDisabledReturns404AndNeverCallsTheFacade(): void
    {
        $b = $this->build(self::SAME_ORIGIN, false, CheckoutConfig::MODE_NATIVE, true);
        $b['facade']->expects($this->never())->method('place');

        $b['controller']->execute();

        $this->assertSame(404, $b['code']);
        $this->assertSame('not_available', $b['data']['error']);
    }

    // --- 3. Missing or wrong form key: rejected by validateForCsrf -----

    public function testValidateForCsrfRejectsAMissingFormKey(): void
    {
        $b = $this->build(
            null,
            true,
            CheckoutConfig::MODE_NATIVE,
            true,
            [],
            (string) json_encode(['payment_method' => 'checkmo'])
        );
        $this->wireRequestParams($b['request']);

        $this->assertFalse($b['controller']->validateForCsrf($b['request']));
    }

    public function testValidateForCsrfRejectsAWrongFormKey(): void
    {
        $b = $this->build(
            null,
            true,
            CheckoutConfig::MODE_NATIVE,
            true,
            [],
            (string) json_encode(['payment_method' => 'checkmo', 'form_key' => 'not-the-real-key'])
        );
        $this->wireRequestParams($b['request']);

        $this->assertFalse($b['controller']->validateForCsrf($b['request']));
    }

    public function testValidateForCsrfAcceptsTheMatchingFormKey(): void
    {
        $b = $this->build(
            null,
            true,
            CheckoutConfig::MODE_NATIVE,
            true,
            [],
            (string) json_encode(['payment_method' => 'checkmo', 'form_key' => 'the-real-form-key'])
        );
        $this->wireRequestParams($b['request']);

        $this->assertTrue($b['controller']->validateForCsrf($b['request']));
    }

    /**
     * DEVIATION FROM THE BRIEF, verified on the wire: returning null here
     * does NOT yield Magento's "standard 403" — CsrfValidator::
     * createException() falls back to a 302 redirect on a concrete false
     * from validateForCsrf(), which fails the module's own
     * `{"error": snake_case_code}` contract and breaks the widget's
     * `r.json()` parsing. See Place.php's createCsrfValidationException()
     * docblock and the task report for the curl evidence. We build the 403
     * JSON response ourselves instead of returning null.
     */
    public function testCreateCsrfValidationExceptionReturnsAJson403InsteadOfNull(): void
    {
        $b = $this->build(null, true, CheckoutConfig::MODE_NATIVE, true);
        $exception = $b['controller']->createCsrfValidationException($b['request']);

        $this->assertInstanceOf(\Magento\Framework\App\Request\InvalidRequestException::class, $exception);
        $this->assertSame(403, $b['code']);
        $this->assertSame('invalid_form_key', $b['data']['error']);
    }

    /**
     * The real FormKey\Validator::validate() reads $request->getParam('form_key').
     * Our controller bridges the JSON body into request params via
     * setParams() before delegating — wire the mock's setParams/getParam
     * together so that bridge is actually exercised, not assumed.
     */
    private function wireRequestParams($request): void
    {
        $params = [];
        $request->method('setParams')->willReturnCallback(function (array $p) use (&$params) {
            $params = array_merge($params, $p);
        });
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default = null) use (&$params) {
                return $params[$key] ?? $default;
            }
        );
    }

    // --- 4. Rate limited -------------------------------------------------

    public function testOverTheRateLimitReturns429AndNeverCallsTheFacade(): void
    {
        $b = $this->build(self::SAME_ORIGIN, true, CheckoutConfig::MODE_NATIVE, false);
        $b['facade']->expects($this->never())->method('place');

        $b['controller']->execute();

        $this->assertSame(429, $b['code']);
        $this->assertSame('rate_limited', $b['data']['error']);
    }

    // --- 5. LocalizedException from the facade → 400 with message passed through ---

    public function testLocalizedExceptionFromTheFacadeBecomes400WithTheMessagePassedThrough(): void
    {
        $b = $this->build(self::SAME_ORIGIN, true, CheckoutConfig::MODE_NATIVE, true, [
            'throw' => new LocalizedException(__('That payment method is not available.')),
        ]);

        $b['controller']->execute();

        $this->assertSame(400, $b['code']);
        $this->assertSame('checkout_error', $b['data']['error']);
        $this->assertSame('That payment method is not available.', $b['data']['message']);
    }

    // --- 6. \RuntimeException from the facade → 500, message NOT in the body ---

    public function testRuntimeExceptionFromTheFacadeBecomes500AndTheMessageIsNotInTheBody(): void
    {
        $b = $this->build(self::SAME_ORIGIN, true, CheckoutConfig::MODE_NATIVE, true, [
            'throw' => new \RuntimeException('SQLSTATE[42S02]: table orders_secret does not exist'),
        ]);
        $b['logger']->expects($this->once())->method('error');

        $b['controller']->execute();

        $this->assertSame(500, $b['code']);
        $this->assertSame('internal_error', $b['data']['error']);
        $this->assertArrayNotHasKey('message', $b['data']);
        $encoded = (string) json_encode($b['data']);
        $this->assertStringNotContainsString('SQLSTATE', $encoded);
        $this->assertStringNotContainsString('orders_secret', $encoded);
    }

    // --- 7. Success returns the façade's order_id verbatim ---------------

    public function testSuccessReturnsTheFacadesOrderIdVerbatim(): void
    {
        $b = $this->build(self::SAME_ORIGIN, true, CheckoutConfig::MODE_NATIVE, true, [
            'result' => ['order_id' => '100000456', 'total' => '12.34', 'currency' => 'GBP'],
        ]);

        $b['controller']->execute();

        $this->assertSame(200, $b['code']);
        $this->assertSame('100000456', $b['data']['order_id']);
        $this->assertSame('12.34', $b['data']['total']);
    }

    /**
     * Deliberately left with a missing Origin (guard 1 now rejects that on
     * this route) — this is the strongest version of the assertion: the
     * Cache-Control header must be set even on the very first guard's
     * failure response, since it's the first statement in execute(),
     * before any guard runs.
     */
    public function testEveryResponseSetsCacheControlNoStore(): void
    {
        $b = $this->build(null, true, CheckoutConfig::MODE_NATIVE, true);
        $b['controller']->execute();
        $this->assertSame('no-store', $b['headers']['Cache-Control'] ?? null);
    }

    // --- Minor: a non-string JSON value must not raise a PHP warning -----

    /**
     * Magento\Framework\Data\Form\FormKey\Validator::validate() would
     * receive whatever we bridge in via setParams(); before the
     * is_string() guard, a JSON array under "form_key" would (string)-cast
     * to "Array" with a PHP warning — fatal under this suite's
     * failOnWarning=true, so this test fails loudly if the guard regresses.
     */
    public function testValidateForCsrfRejectsAnArrayFormKeyWithoutARuntimeWarning(): void
    {
        $b = $this->build(
            self::SAME_ORIGIN,
            true,
            CheckoutConfig::MODE_NATIVE,
            true,
            [],
            (string) json_encode(['payment_method' => 'checkmo', 'form_key' => ['nested', 'array']])
        );
        $this->wireRequestParams($b['request']);

        $this->assertFalse($b['controller']->validateForCsrf($b['request']));
    }

    /**
     * Same guard, exercised on execute()'s payment_method field instead of
     * validateForCsrf()'s form_key. An array payment_method is coerced to
     * '' (not "Array", and not a PHP warning), which the façade's own
     * allowlist re-check would reject anyway — proving that here, not
     * assuming it, means asserting the façade actually receives ''.
     */
    public function testAnArrayPaymentMethodIsCoercedToEmptyStringWithoutARuntimeWarning(): void
    {
        $b = $this->build(
            self::SAME_ORIGIN,
            true,
            CheckoutConfig::MODE_NATIVE,
            true,
            ['skipDefaultStub' => true],
            (string) json_encode(['payment_method' => ['not', 'a', 'string']])
        );
        $b['facade']->expects($this->once())->method('place')->with('')->willReturn([
            'order_id' => '1', 'total' => '0.00', 'currency' => 'GBP',
        ]);

        $b['controller']->execute();
    }

    // --- Round 2: the IP bucket's identity must come from RemoteAddress ---

    /**
     * Round 2 review finding: the IP passed to CheckoutRateLimit must come
     * from Magento\Framework\HTTP\PhpEnvironment\RemoteAddress, not
     * $request->getClientIp() (which trusts an unrestricted
     * X-Forwarded-For). This pins the wiring: whatever RemoteAddress
     * returns is exactly what checkPlace() receives as the ip argument.
     */
    public function testTheIpBucketIsSourcedFromRemoteAddress(): void
    {
        $b = $this->build(
            self::SAME_ORIGIN,
            true,
            CheckoutConfig::MODE_NATIVE,
            true,
            [],
            null,
            '198.51.100.7' // TEST-NET-2 (RFC 5737) — distinct from the default fixture IP.
        );

        $b['controller']->execute();

        $this->assertCount(1, $b['rateLimitCalls']);
        $this->assertSame('198.51.100.7', $b['rateLimitCalls'][0]['ip']);
    }

    /**
     * The point of the IP bucket is that two different remote addresses are
     * two different callers as far as CheckoutRateLimit is concerned — this
     * proves the controller doesn't collapse them into a shared value
     * (e.g. always deriving from the session, or a constant) before they
     * ever reach the rate limiter, which is exactly what would make the
     * bucket decorative regardless of how CheckoutRateLimit itself buckets
     * by ip (already covered independently by
     * CheckoutRateLimitTest::testDeniesWhenTheIpBucketIsAtItsCeilingEvenWithAFreshSession).
     */
    public function testTwoDifferentRemoteAddressesProduceTwoDifferentIpArguments(): void
    {
        $first = $this->build(
            self::SAME_ORIGIN,
            true,
            CheckoutConfig::MODE_NATIVE,
            true,
            [],
            null,
            '198.51.100.7'
        );
        $second = $this->build(
            self::SAME_ORIGIN,
            true,
            CheckoutConfig::MODE_NATIVE,
            true,
            [],
            null,
            '203.0.113.99'
        );

        $first['controller']->execute();
        $second['controller']->execute();

        $this->assertSame('198.51.100.7', $first['rateLimitCalls'][0]['ip']);
        $this->assertSame('203.0.113.99', $second['rateLimitCalls'][0]['ip']);
        $this->assertNotSame($first['rateLimitCalls'][0]['ip'], $second['rateLimitCalls'][0]['ip']);
    }
}
