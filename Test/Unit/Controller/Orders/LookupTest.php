<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Controller\Orders;

use Idea89\Assistant\Controller\Orders\Lookup;
use Idea89\Assistant\Model\GuestLookupRateLimit;
use Idea89\Assistant\Model\OrderSanitizer;
use Idea89\Assistant\Model\OrderTrackingConfig;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guest order lookup must not be usable as an enumeration oracle: every
 * attempt counts against the bucket, the bucket is keyed off the real peer
 * address, and malformed input looks exactly like a miss.
 */
class LookupTest extends TestCase
{
    private const ORIGIN = 'https://app.magento2.test';

    /**
     * @param list<OrderInterface> $orders What the repository finds.
     */
    private function build(?string $rawBody, array $orders = [], string $remoteIp = '203.0.113.5'): array
    {
        $jsonData = null;
        $jsonCode = 200;
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json, &$jsonData) {
            $jsonData = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($json, &$jsonCode) {
            $jsonCode = $code;
            return $json;
        });
        $json->method('setHeader')->willReturn($json);
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(
            fn (string $name) => $name === 'Origin' ? self::ORIGIN : false
        );
        $request->method('getContent')->willReturn($rawBody ?? '');
        // A spoofable forwarded address the controller must NOT use.
        $request->method('getClientIp')->willReturn('198.51.100.77');

        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn($remoteIp);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn(self::ORIGIN . '/');
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnSelf();
        $criteriaBuilder->method('setPageSize')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $list = $this->createMock(OrderSearchResultInterface::class);
        $list->method('getItems')->willReturn($orders);
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('getList')->willReturn($list);

        $sanitizer = $this->createMock(OrderSanitizer::class);
        $sanitizer->method('sanitize')->willReturn(['increment_id' => '100000123']);

        $config = $this->createMock(OrderTrackingConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $rateLimitIps = [];
        $rateLimit = $this->createMock(GuestLookupRateLimit::class);
        $rateLimit->method('check')->willReturnCallback(function (string $ip) use (&$rateLimitIps) {
            $rateLimitIps[] = $ip;
            return ['allowed' => true, 'attempts' => 1, 'retry_after' => 0];
        });

        $controller = new Lookup(
            $jsonFactory,
            $repository,
            $criteriaBuilder,
            $storeManager,
            $sanitizer,
            $config,
            $rateLimit,
            $request,
            $this->createMock(LoggerInterface::class),
            $remoteAddress
        );

        return [
            'controller' => $controller,
            'rateLimit' => $rateLimit,
            'rateLimitIps' => &$rateLimitIps,
            'code' => &$jsonCode,
            'data' => &$jsonData,
        ];
    }

    private function order(string $email): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomerEmail')->willReturn($email);
        return $order;
    }

    private function body(string $incrementId, string $email): string
    {
        return (string) json_encode(['increment_id' => $incrementId, 'email' => $email]);
    }

    public function testASuccessfulLookupDoesNotResetTheRateLimitBucket(): void
    {
        $b = $this->build($this->body('100000123', 'shopper@example.com'), [$this->order('shopper@example.com')]);
        $b['rateLimit']->expects($this->never())->method('reset');

        $b['controller']->execute();

        $this->assertSame(200, $b['code']);
        $this->assertArrayHasKey('order', $b['data']);
    }

    public function testTheBucketIsKeyedOffTheRemoteAddressNotForwardedHeaders(): void
    {
        $b = $this->build($this->body('100000123', 'shopper@example.com'), [], '203.0.113.9');

        $b['controller']->execute();

        $this->assertSame(['203.0.113.9'], $b['rateLimitIps']);
    }

    public function testEmailMatchIsCaseInsensitiveAndTrimmed(): void
    {
        $b = $this->build($this->body('100000123', '  Shopper@Example.COM '), [$this->order('shopper@example.com')]);

        $b['controller']->execute();

        $this->assertSame(200, $b['code']);
    }

    public function testEmailMismatchLooksExactlyLikeNotFound(): void
    {
        $mismatch = $this->build($this->body('100000123', 'other@example.com'), [$this->order('shopper@example.com')]);
        $mismatch['controller']->execute();

        $missing = $this->build($this->body('100000999', 'other@example.com'), []);
        $missing['controller']->execute();

        $this->assertSame(404, $mismatch['code']);
        $this->assertSame([$missing['code'], $missing['data']], [$mismatch['code'], $mismatch['data']]);
    }

    public function testInvalidJsonLooksExactlyLikeNotFound(): void
    {
        $invalid = $this->build('{not json');
        $invalid['controller']->execute();

        $missing = $this->build($this->body('100000999', 'other@example.com'), []);
        $missing['controller']->execute();

        $this->assertSame(404, $invalid['code']);
        $this->assertSame(['error' => 'order_not_found'], $invalid['data']);
        $this->assertSame([$missing['code'], $missing['data']], [$invalid['code'], $invalid['data']]);
    }
}
