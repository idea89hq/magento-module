<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Cron;

use Idea89\Assistant\Cron\HandOverBrandColour;
use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The removed Brand Colour field is sent to IDEA89 once per saved value, and
 * its config row is deleted only after IDEA89 confirms.
 */
class HandOverBrandColourTest extends TestCase
{
    /** @var list<int> */
    private array $deleted = [];

    /** @var list<array{0:string,1:string}> */
    private array $sent = [];

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,string> $keys scope "type:code" => API key
     */
    private function handOver(array $rows, array $keys, bool $apiOk = true): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchAll')->willReturn($rows);
        $conn->method('delete')->willReturnCallback(function (string $t, array $where) {
            $this->deleted[] = (int) $where['config_id = ?'];
            return 1;
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturnCallback(
            fn (string $type, ?string $code) => $keys[$type . ':' . ($code ?? '')] ?? ''
        );
        $config->method('getApiUrl')->willReturn('https://api.test');

        $client = $this->createMock(Idea89Client::class);
        $client->method('seedBrandColor')->willReturnCallback(function (string $c, string $key) use ($apiOk) {
            $this->sent[] = [$c, $key];
            return $apiOk;
        });

        (new HandOverBrandColour($resource, $config, $client, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testSendsEachValueDefaultScopeFirstAndDeletesOnConfirm(): void
    {
        $this->handOver(
            [
                ['config_id' => 9, 'scope' => 'stores', 'scope_id' => 2, 'value' => '#112233'],
                ['config_id' => 4, 'scope' => 'default', 'scope_id' => 0, 'value' => ' #2563EB '],
            ],
            ['default:' => 'k-default', 'stores:2' => 'k-store2']
        );
        self::assertSame([['#2563EB', 'k-default'], ['#112233', 'k-store2']], $this->sent);
        self::assertSame([4, 9], $this->deleted);
    }

    public function testKeepsTheRowWhenIdea89CannotBeReached(): void
    {
        $this->handOver([['config_id' => 4, 'scope' => 'default', 'scope_id' => 0, 'value' => '#2563eb']], ['default:' => 'k'], false);
        self::assertCount(1, $this->sent);
        self::assertSame([], $this->deleted);
    }

    public function testWaitsWhileTheStoreIsNotConnected(): void
    {
        $this->handOver([['config_id' => 4, 'scope' => 'default', 'scope_id' => 0, 'value' => '#2563eb']], []);
        self::assertSame([], $this->sent);
        self::assertSame([], $this->deleted);
    }

    public function testDropsValuesThatAreNotAColourWithoutSendingThem(): void
    {
        $this->handOver(
            [
                ['config_id' => 1, 'scope' => 'default', 'scope_id' => 0, 'value' => ''],
                ['config_id' => 2, 'scope' => 'websites', 'scope_id' => 1, 'value' => 'red'],
            ],
            ['default:' => 'k', 'websites:1' => 'k']
        );
        self::assertSame([], $this->sent);
        self::assertSame([1, 2], $this->deleted);
    }

    public function testDoesNothingWhenNoValueIsSaved(): void
    {
        $this->handOver([], ['default:' => 'k']);
        self::assertSame([], $this->sent);
        self::assertSame([], $this->deleted);
    }
}
