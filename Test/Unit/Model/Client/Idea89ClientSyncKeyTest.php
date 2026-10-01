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

    /**
     * @param int[] $statuses one per request, in order
     * @param string[] $bodies one per request, in order
     */
    private function withResponses(array $statuses, array $bodies, string $syncKey = 'sk'): Idea89Client
    {
        // Answer per request, however many times the client reads the response.
        $curl = $this->createMock(Curl::class);
        $request = -1;
        $next = function () use (&$request): void {
            $request++;
        };
        $curl->method('get')->willReturnCallback($next);
        $curl->method('post')->willReturnCallback($next);
        $curl->method('getStatus')->willReturnCallback(function () use (&$request, $statuses) {
            return $statuses[$request];
        });
        $curl->method('getBody')->willReturnCallback(function () use (&$request, $bodies) {
            return $bodies[$request];
        });
        $config = $this->createMock(Config::class);
        $config->method('getSyncKey')->willReturn($syncKey);
        return new Idea89Client(
            $curl,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ScopeConfigInterface::class)
        );
    }

    public function testASyncKeyRefusalIsRecordedWithTheApiMessage(): void
    {
        $client = $this->withResponses([401], ['{"error":"sync_key_not_set","message":"Create a key under API & Domains."}']);

        $this->assertFalse($client->upsertProducts([['external_id' => '1']], 'k', 'https://api.test'));
        $this->assertSame('Create a key under API & Domains.', $client->getSyncKeyRejection());
    }

    public function testOtherFailuresAreNotMistakenForASyncKeyRefusal(): void
    {
        $client = $this->withResponses([500, 401], ['oops', '{"error":"invalid_api_key"}']);

        $this->assertFalse($client->upsertContent([['type' => 'page']], 'k', 'https://api.test'));
        $this->assertFalse($client->upsertStock([['external_id' => '1']], 'k', 'https://api.test'));
        $this->assertNull($client->getSyncKeyRejection());
    }

    public function testConnectionReportsAMissingSyncKey(): void
    {
        $client = $this->withResponses(
            [200, 401],
            ['{"status":"ok"}', '{"error":"sync_key_required","message":"Paste the key into the plugin settings."}'],
            ''
        );

        $result = $client->testConnection('k', 'https://api.test');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Paste the key into the plugin settings.', $result['error']);
    }

    public function testConnectionPassesWhenCatalogueAccessIsAccepted(): void
    {
        $this->assertSame(['ok' => true], $this->withResponses([200, 200], ['{}', '{"ok":true}'])->testConnection('k', 'https://api.test'));
        // An API that predates /v1/catalog/verify: the health check stands.
        $this->assertSame(['ok' => true], $this->withResponses([200, 404], ['{}', ''])->testConnection('k', 'https://api.test'));
    }
}
