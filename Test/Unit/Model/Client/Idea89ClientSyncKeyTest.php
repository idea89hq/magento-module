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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The optional catalog sync key rides on every catalog write when the
 * merchant has set one, and is absent (behaviour unchanged) when they have not.
 */
class Idea89ClientSyncKeyTest extends TestCase
{
    /**
     * @param array<string,string> $headers Filled with every header the client adds.
     */
    private function build(string $syncKey, array &$headers): Idea89Client
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('addHeader')->willReturnCallback(function (string $name, string $value) use (&$headers) {
            $headers[$name] = $value;
        });
        $curl->method('getStatus')->willReturn(200);

        $config = $this->createMock(Config::class);
        $config->method('getSyncKey')->willReturn($syncKey);

        return new Idea89Client(
            $curl,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ScopeConfigInterface::class)
        );
    }

    public function testEveryCatalogWriteCarriesTheSyncKeyWhenSet(): void
    {
        $calls = [
            fn (Idea89Client $c) => $c->upsertProducts([['external_id' => '1']], 'k', 'https://api.test'),
            fn (Idea89Client $c) => $c->upsertContent([['type' => 'page']], 'k', 'https://api.test'),
            fn (Idea89Client $c) => $c->upsertPromos([['code' => 'X']], 'k', 'https://api.test'),
            fn (Idea89Client $c) => $c->upsertStock([['external_id' => '1']], 'k', 'https://api.test'),
        ];
        foreach ($calls as $call) {
            $headers = [];
            $client = $this->build('sync_secret_123', $headers);
            $this->assertTrue($call($client));
            $this->assertSame('sync_secret_123', $headers['X-IDEA89-Sync-Key'] ?? null);
            $this->assertSame('k', $headers['X-IDEA89-Key'] ?? null);
        }
    }

    public function testNoSyncKeyHeaderWhenUnset(): void
    {
        $headers = [];
        $client = $this->build('', $headers);

        $this->assertTrue($client->upsertProducts([['external_id' => '1']], 'k', 'https://api.test'));
        $this->assertArrayNotHasKey('X-IDEA89-Sync-Key', $headers);
        $this->assertSame('k', $headers['X-IDEA89-Key'] ?? null);
    }
}
