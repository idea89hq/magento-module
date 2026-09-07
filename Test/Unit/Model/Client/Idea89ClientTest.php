<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Client;

use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Magento's Curl client THROWS on a transport failure rather than returning a
 * status to inspect: doError() raises, so getStatus() is never reached.
 *
 * That mattered here. updateCheckoutUi() originally only checked the status,
 * so a refused connection escaped the config backend model's beforeSave() as
 * an uncaught fatal, and a merchant whose API was unreachable got a 500 on the
 * settings page instead of the "could not reach IDEA89" message. It was found
 * by pointing a live store at a dead port; the backend model's own unit test
 * mocks this client returning false and so cannot reach the throw at all.
 */
class Idea89ClientTest extends TestCase
{
    /** @var Curl&MockObject */
    private $curl;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private Idea89Client $client;

    protected function setUp(): void
    {
        $this->curl   = $this->createMock(Curl::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->client = new Idea89Client(
            $this->curl,
            $this->createMock(Config::class),
            $this->logger,
            $this->createMock(ScopeConfigInterface::class)
        );
    }

    public function testAConnectionFailureIsReportedAsFalseNotThrown(): void
    {
        // The regression. Anything escaping here becomes a 500 on the admin
        // settings page rather than a message the merchant can act on.
        $this->curl->method('post')
            ->willThrowException(new \Exception('Failed to connect to 127.0.0.1 port 9: Connection refused'));

        $this->assertFalse(
            $this->client->updateCheckoutUi('inline', 'idea89_live_key', 'http://127.0.0.1:9')
        );
    }

    public function testANonSuccessStatusIsAlsoFalse(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('{"error":"boom"}');

        $this->assertFalse(
            $this->client->updateCheckoutUi('inline', 'idea89_live_key', 'https://api.idea89.com')
        );
    }

    public function testA200IsTrue(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"checkout_ui":"inline"}');

        $this->assertTrue(
            $this->client->updateCheckoutUi('inline', 'idea89_live_key', 'https://api.idea89.com')
        );
    }

    public function testTheValueAndKeyActuallyReachTheRequest(): void
    {
        // A method that returns true without sending the value would pass
        // every assertion above.
        $this->curl->expects($this->once())
            ->method('post')
            ->with(
                'https://api.idea89.com/v1/plugin-settings',
                $this->callback(
                    static fn ($body) => json_decode((string) $body, true) === ['checkout_ui' => 'inline']
                )
            );
        $this->curl->expects($this->atLeastOnce())
            ->method('addHeader')
            ->willReturnCallback(
                function (string $name, string $value) {
                    if ('X-IDEA89-Key' === $name) {
                        $this->assertSame('idea89_live_key', $value);
                    }
                }
            );
        $this->curl->method('getStatus')->willReturn(200);

        $this->client->updateCheckoutUi('inline', 'idea89_live_key', 'https://api.idea89.com');
    }
}
