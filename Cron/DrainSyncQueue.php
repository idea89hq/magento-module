<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Sync\CatalogSyncer;
use Idea89\Assistant\Model\Sync\StockPayloadBuilder;
use Idea89\Assistant\Model\Sync\SyncQueue;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigCollectionFactory;

/**
 * Drains the pending queues every minute (crontab.xml): products saved
 * (ProductSaved), products deleted (ProductDeleted) and products whose stock
 * changed (OrderStockChanged, StockItemSaveAfter). What the API could not
 * take for a reason a retry can fix is queued again for the next run.
 */
class DrainSyncQueue
{
    private const XML_PATH_QUEUE = 'idea89/sync/pending_product_ids';

    public function __construct(
        private readonly Config $config,
        private readonly CatalogSyncer $syncer,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger,
        private readonly SyncQueue $queue,
        private readonly Idea89Client $client,
        private readonly StockPayloadBuilder $stockPayload,
        private readonly ConfigCollectionFactory $configCollectionFactory,
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->getApiKey()) {
            return;
        }

        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();

        // A write that fails in a way a retry can fix (API unreachable,
        // rate-limited or erroring) puts its ids back for the next run instead
        // of leaving them to the nightly sync. After the first such failure
        // the rest of the run is queued again without being sent, so a down
        // API costs one attempt a minute, not one timeout per product.
        $this->client->takeRetryableFailure();

        $deleted = $this->queue->take(SyncQueue::DELETED);
        if ($deleted !== []) {
            $this->client->deleteProducts($deleted, $apiKey, $apiUrl);
            if ($this->client->takeRetryableFailure()) {
                $this->requeueRest($deleted, $this->queue->take(SyncQueue::STOCK), $this->pendingProducts());
                return;
            }
            $this->logger->info('IDEA89: removed deleted products', ['count' => count($deleted)]);
        }

        $stock = $this->queue->take(SyncQueue::STOCK);
        if ($stock !== []) {
            foreach (array_chunk($this->stockPayload->build(array_map('intval', $stock)), 500) as $items) {
                $this->client->upsertStock($items, $apiKey, $apiUrl);
                if ($this->client->takeRetryableFailure()) {
                    // Stock is a full overwrite per product, so resending all is safe.
                    $this->requeueRest([], $stock, $this->pendingProducts());
                    return;
                }
            }
        }

        $ids = $this->pendingProducts();
        if (empty($ids)) {
            return;
        }

        $this->logger->info('IDEA89: draining sync queue', ['count' => count($ids)]);

        foreach (array_values($ids) as $i => $productId) {
            if (!$this->syncer->syncProduct((int) $productId)) {
                $this->requeueRest([], [], array_slice($ids, $i));
                return;
            }
        }
    }

    /**
     * Ids waiting in the product queue, plus any an older version left at its
     * core_config_data path (read from the database, which the config cache
     * never saw), which is then cleared.
     *
     * @return string[]
     */
    private function pendingProducts(): array
    {
        $ids = $this->queue->take(SyncQueue::PRODUCTS);
        $legacy = $this->configCollectionFactory->create()
            ->addFieldToFilter('path', self::XML_PATH_QUEUE)
            ->addFieldToFilter('scope', 'default');
        foreach ($legacy as $row) {
            $old = array_filter(explode(',', (string) $row->getValue()));
            if ($old !== []) {
                $ids = array_values(array_unique(array_merge($ids, $old)));
                $this->configWriter->save(self::XML_PATH_QUEUE, '');
            }
        }
        return array_values(array_map('strval', $ids));
    }

    /**
     * @param string[] $deleted
     * @param string[] $stock
     * @param string[] $products
     */
    private function requeueRest(array $deleted, array $stock, array $products): void
    {
        $this->queue->push(SyncQueue::DELETED, $deleted);
        $this->queue->push(SyncQueue::STOCK, $stock);
        $this->queue->push(SyncQueue::PRODUCTS, $products);
        $this->logger->warning('IDEA89: API unavailable, queued again for the next run', [
            'deleted' => count($deleted),
            'stock' => count($stock),
            'products' => count($products),
        ]);
    }
}
