<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Cron;

use Idea89\Assistant\Cron\DrainSyncQueue;
use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\CatalogSyncer;
use Idea89\Assistant\Model\Sync\StockPayloadBuilder;
use Idea89\Assistant\Model\Sync\SyncQueue;
use Magento\Config\Model\ResourceModel\Config\Data\Collection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the API could not take for a reason a retry can fix goes back on the
 * queue for the next run; a failure a retry would repeat does not.
 */
class DrainSyncQueueRequeueTest extends TestCase
{
    /** @var array<string, string> */
    private array $flags = [];

    private function queue(): SyncQueue
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn (string $k) => $this->flags[$k] ?? null);
        $flags->method('saveFlag')->willReturnCallback(function (string $k, $v) {
            $this->flags[$k] = (string) $v;
            return true;
        });
        return new SyncQueue($flags);
    }

    private function drain(SyncQueue $queue, CatalogSyncer $syncer, Idea89Client $client): DrainSyncQueue
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getApiKey')->willReturn('k');
        $config->method('getApiUrl')->willReturn('https://api.test');
        $legacy = $this->createMock(Collection::class);
        $legacy->method('addFieldToFilter')->willReturnSelf();
        $legacy->method('getIterator')->willReturn(new \ArrayIterator([]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($legacy);
        $stock = $this->createMock(StockPayloadBuilder::class);
        $stock->method('build')->willReturnCallback(
            fn (array $ids) => array_map(fn ($id) => ['external_id' => (string) $id, 'in_stock' => true], $ids)
        );
        return new DrainSyncQueue(
            $config,
            $syncer,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(WriterInterface::class),
            $this->createMock(LoggerInterface::class),
            $queue,
            $client,
            $stock,
            $factory
        );
    }

    public function testProductsFromTheFirstRetryableFailureOnAreQueuedAgainUnsent(): void
    {
        $queue = $this->queue();
        $queue->push(SyncQueue::PRODUCTS, [1, 2, 3]);
        $syncer = $this->createMock(CatalogSyncer::class);
        // 1 is sent, 2 hits a down API; 3 is not attempted.
        $syncer->expects($this->exactly(2))->method('syncProduct')
            ->willReturnCallback(fn (int $id) => $id === 1);

        $this->drain($queue, $syncer, $this->createMock(Idea89Client::class))->execute();

        $this->assertSame(['2', '3'], $queue->take(SyncQueue::PRODUCTS));
    }

    public function testEverythingSentLeavesTheQueuesEmpty(): void
    {
        $queue = $this->queue();
        $queue->push(SyncQueue::PRODUCTS, [1, 2]);
        $queue->push(SyncQueue::STOCK, [4]);
        $queue->push(SyncQueue::DELETED, [9]);
        $syncer = $this->createMock(CatalogSyncer::class);
        $syncer->method('syncProduct')->willReturn(true);
        $client = $this->createMock(Idea89Client::class);
        $client->method('takeRetryableFailure')->willReturn(false);

        $this->drain($queue, $syncer, $client)->execute();

        $this->assertSame([], $queue->take(SyncQueue::PRODUCTS));
        $this->assertSame([], $queue->take(SyncQueue::STOCK));
        $this->assertSame([], $queue->take(SyncQueue::DELETED));
    }

    public function testAFailedDeleteQueuesItselfAndLeavesStockAndProductsForTheNextRun(): void
    {
        $queue = $this->queue();
        $queue->push(SyncQueue::DELETED, [9]);
        $queue->push(SyncQueue::STOCK, [4]);
        $queue->push(SyncQueue::PRODUCTS, [1]);
        $client = $this->createMock(Idea89Client::class);
        // The reset at the start, then the delete's failure.
        $client->method('takeRetryableFailure')->willReturnOnConsecutiveCalls(false, true);
        $client->expects($this->never())->method('upsertStock');
        $syncer = $this->createMock(CatalogSyncer::class);
        $syncer->expects($this->never())->method('syncProduct');

        $this->drain($queue, $syncer, $client)->execute();

        $this->assertSame(['9'], $queue->take(SyncQueue::DELETED));
        $this->assertSame(['4'], $queue->take(SyncQueue::STOCK));
        $this->assertSame(['1'], $queue->take(SyncQueue::PRODUCTS));
    }

    public function testAFailedStockSendQueuesTheStockAgain(): void
    {
        $queue = $this->queue();
        $queue->push(SyncQueue::STOCK, [4, 5]);
        $client = $this->createMock(Idea89Client::class);
        $client->method('takeRetryableFailure')->willReturnOnConsecutiveCalls(false, true);
        $syncer = $this->createMock(CatalogSyncer::class);
        $syncer->expects($this->never())->method('syncProduct');

        $this->drain($queue, $syncer, $client)->execute();

        $this->assertSame(['4', '5'], $queue->take(SyncQueue::STOCK));
    }
}
