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

/** Schema 2 on the wire, and deletions through the endpoint every API has. */
class Idea89ClientCatalogSchemaTest extends TestCase
{
    /** @var array<int, array{0: string, 1: string}> */
    private array $posts = [];

    private function client(int $status = 200): Idea89Client
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willReturnCallback(function (string $url, $body): void { $this->posts[] = [$url, $body]; });
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn('{}');
        $config = $this->createMock(Config::class);
        $config->method('getSyncKey')->willReturn('');
        return new Idea89Client($curl, $config, $this->createMock(LoggerInterface::class), $this->createMock(ScopeConfigInterface::class));
    }

    public function testUpsertSendsSchemaVersionAndPlatform(): void
    {
        $this->assertTrue($this->client()->upsertProducts([['external_id' => '1', 'attribute_list' => []]], 'k', 'https://api.test'));
        $body = json_decode($this->posts[0][1], true);
        $this->assertSame('https://api.test/v1/catalog/upsert', $this->posts[0][0]);
        $this->assertSame(2, $body['schema_version']);
        $this->assertSame('magento2', $body['platform']);
        $this->assertSame('1', $body['products'][0]['external_id']);
    }

    public function testDeletionsUseTheDeleteEndpointInChunksOf500(): void
    {
        $ids = array_map('strval', range(1, 1200));
        $this->assertTrue($this->client()->deleteProducts([...$ids, '1', ''], 'k', 'https://api.test'));
        $this->assertCount(3, $this->posts);
        $this->assertSame('https://api.test/v1/catalog/delete', $this->posts[0][0]);
        $this->assertCount(500, json_decode($this->posts[0][1], true)['external_ids']);
        $this->assertCount(200, json_decode($this->posts[2][1], true)['external_ids']);
        $this->assertTrue($this->client()->deleteProducts([], 'k', 'https://api.test'));
    }

    public function testAFailedDeletionReportsFalse(): void
    {
        $this->assertFalse($this->client(500)->deleteProducts(['9'], 'k', 'https://api.test'));
    }
}
