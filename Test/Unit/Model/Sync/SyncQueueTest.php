<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Sync\SyncQueue;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;

class SyncQueueTest extends TestCase
{
    public function testPushDeduplicatesAndTakeEmpties(): void
    {
        $store = [];
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(function (string $k) use (&$store) { return $store[$k] ?? null; });
        $flags->method('saveFlag')->willReturnCallback(function (string $k, $v) use (&$store) { $store[$k] = $v; return true; });
        $q = new SyncQueue($flags);
        $q->push(SyncQueue::STOCK, [5, 6]);
        $q->push(SyncQueue::STOCK, [6, '7']);
        $this->assertSame(['5', '6', '7'], $q->take(SyncQueue::STOCK));
        $this->assertSame([], $q->take(SyncQueue::STOCK));
        $this->assertSame([], $q->take(SyncQueue::DELETED));
    }
}
