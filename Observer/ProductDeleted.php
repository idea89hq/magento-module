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
 * Queues a deleted product's removal from the assistant. The drain cron
 * sends it within a minute; no HTTP call is made during the delete.
 */
class ProductDeleted implements ObserverInterface
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
        $product = $observer->getEvent()->getProduct();
        $id = $product ? (int) $product->getId() : 0;
        if ($id > 0) {
            $this->queue->push(SyncQueue::DELETED, [$id]);
        }
    }
}
