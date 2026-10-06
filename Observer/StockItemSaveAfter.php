<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\SyncQueue;

/**
 * Queues a product's stock for the drain cron whenever its stock item is
 * saved (admin edit, import, a non-MSI order).
 *
 * The stock is not read here: with multi-source inventory the salable
 * quantity for this save is not up to date until after it, and reading it
 * inside the save sent the previous quantity (found on a local store,
 * 1.4.0). The drain cron, within a minute, sends the salable quantity for the
 * product and, for a configurable child, for its variant inside each parent
 * (StockPayloadBuilder). No HTTP call is made during the save.
 */
class StockItemSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SyncQueue $queue,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        /** @var \Magento\CatalogInventory\Model\Stock\Item $item */
        $item = $observer->getEvent()->getItem();
        if (!$item || !$item->getProductId()) {
            return;
        }

        $this->queue->push(SyncQueue::STOCK, [(int) $item->getProductId()]);
    }
}
