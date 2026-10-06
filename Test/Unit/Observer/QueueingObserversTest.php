<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Observer;

use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\SyncQueue;
use Idea89\Assistant\Observer\ProductSaved;
use Idea89\Assistant\Observer\StockItemSaveAfter;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Saves only queue (found on a local store, 1.4.0): the saved-product queue
 * was read back through the config cache and never seen, and a stock read
 * inside an MSI save returned the previous quantity.
 */
class QueueingObserversTest extends TestCase
{
    private function observer(array $data): Observer
    {
        $event = new Event($data);
        return new Observer(['event' => $event]);
    }

    private function config(): Config
    {
        $c = $this->createMock(Config::class);
        $c->method('isEnabled')->willReturn(true);
        return $c;
    }

    public function testProductSaveQueuesOnTheFlagTable(): void
    {
        $queue = $this->createMock(SyncQueue::class);
        $queue->expects($this->once())->method('push')->with(SyncQueue::PRODUCTS, [7]);
        (new ProductSaved($this->config(), $queue, $this->createMock(LoggerInterface::class)))
            ->execute($this->observer(['product' => new DataObject(['id' => 7])]));
    }

    public function testStockSaveQueuesInsteadOfReadingStockDuringTheSave(): void
    {
        $queue = $this->createMock(SyncQueue::class);
        $queue->expects($this->once())->method('push')->with(SyncQueue::STOCK, [9]);
        (new StockItemSaveAfter($this->config(), $queue))
            ->execute($this->observer(['item' => new DataObject(['product_id' => 9])]));
    }
}
