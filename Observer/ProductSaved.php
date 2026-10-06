<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\SyncQueue;

/**
 * Queues a product for incremental sync after save.
 * Does NOT make HTTP calls inline — the id goes on the flag-table queue
 * (SyncQueue), which the drain cron empties within a minute.
 */
class ProductSaved implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SyncQueue $queue,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();
        $productId = (int) $product->getId();

        if (!$productId) {
            return;
        }

        $this->queue->push(SyncQueue::PRODUCTS, [$productId]);
        $this->logger->info('IDEA89: queued product for sync', ['product_id' => $productId]);
    }
}
