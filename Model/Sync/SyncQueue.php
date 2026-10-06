<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Framework\FlagManager;

/**
 * Small id queues the drain cron empties every minute: products saved,
 * products deleted in Magento, and products whose stock to push after an
 * order is placed or cancelled. Kept in Magento's flag table, which is read
 * from the database rather than the config cache, so a queued id is seen on
 * the next run without reinitialising config. ids are de-duplicated.
 *
 * Up to 1.3.2 the saved-product queue was a core_config_data value read back
 * through the config cache, which the write never cleared: a saved product
 * waited for a cache flush or the nightly sync. The drain still empties that
 * old path once (DrainSyncQueue).
 */
class SyncQueue
{
    public const PRODUCTS = 'idea89_pending_product_ids';
    public const DELETED  = 'idea89_pending_deleted_ids';
    public const STOCK   = 'idea89_pending_stock_ids';

    /** A queue never holds more than this; the nightly runs reconcile the rest. */
    private const MAX_IDS = 5000;

    public function __construct(
        private readonly FlagManager $flagManager,
    ) {}

    /** @param int[]|string[] $ids */
    public function push(string $queue, array $ids): void
    {
        $current = $this->read($queue);
        $merged = array_slice(array_values(array_unique(array_merge($current, array_map('strval', $ids)))), 0, self::MAX_IDS);
        if ($merged !== $current) {
            $this->flagManager->saveFlag($queue, implode(',', $merged));
        }
    }

    /** @return string[] Every queued id; the queue is emptied. */
    public function take(string $queue): array
    {
        $ids = $this->read($queue);
        if ($ids !== []) {
            $this->flagManager->saveFlag($queue, '');
        }
        return $ids;
    }

    /** @return string[] */
    private function read(string $queue): array
    {
        $raw = (string) $this->flagManager->getFlagData($queue);
        return array_values(array_unique(array_filter(explode(',', $raw), static fn (string $v): bool => $v !== '')));
    }
}
