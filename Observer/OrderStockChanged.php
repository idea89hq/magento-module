<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Observer;

use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\SyncQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Queues the stock of an order's products after it is placed or cancelled.
 *
 * With multi-source inventory an order only places a reservation: neither
 * the source items nor the legacy stock item are saved, so the stock-item
 * observer never fires and the assistant kept the pre-order quantity until
 * the nightly stock run. Without MSI the stock item is saved and this is a
 * harmless second push. The drain cron sends the salable quantities.
 */
class OrderStockChanged implements ObserverInterface
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
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }
        $ids = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->getProductId()) {
                $ids[] = (int) $item->getProductId();
            }
        }
        if ($ids !== []) {
            $this->queue->push(SyncQueue::STOCK, $ids);
        }
    }
}
