<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;

class CatalogSyncer
{
    private const BATCH_SIZE = 100;
    private const XML_PATH_LAST_SYNC = 'idea89/sync/last_full_sync_at';

    private const VISIBLE = [
        Visibility::VISIBILITY_IN_CATALOG,
        Visibility::VISIBILITY_IN_SEARCH,
        Visibility::VISIBILITY_BOTH,
    ];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ProductSerializer $serializer,
        private readonly Idea89Client $client,
        private readonly Config $config,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $collectionFactory,
    ) {}

    /**
     * Full catalog sync — batches of 100, logs progress.
     * Called by the DailySync cron and the "Sync Now" admin button.
     *
     * Products are read in the default store view, so labels, option labels
     * and prices are the storefront's, not the admin's. Products that are
     * disabled or not visible in the catalogue or search are then removed
     * from the assistant (a mass status change fires no save event).
     *
     * @return array{synced: int, failed: int, withdrawn: int}
     */
    public function syncAll(?string $storeCode = null): array
    {
        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();
        $counts = ['synced' => 0, 'failed' => 0, 'withdrawn' => 0];

        if (!$apiKey) {
            $this->logger->warning('IDEA89: syncAll skipped — no API key configured');
            return $counts;
        }

        $this->logger->info('IDEA89: starting full catalog sync');

        $page = 1;
        $synced = 0;
        $failed = 0;

        $this->inStoreView(function () use ($apiKey, $apiUrl, &$page, &$synced, &$failed): void {
            do {
                $criteria = $this->searchCriteriaBuilder
                    ->addFilter('status', Status::STATUS_ENABLED)
                    ->addFilter('visibility', self::VISIBLE, 'in')
                    ->setPageSize(self::BATCH_SIZE)
                    ->setCurrentPage($page)
                    ->create();

                $results = $this->productRepository->getList($criteria);
                $items = $results->getItems();

                if (empty($items)) {
                    break;
                }

                $batch = array_map(
                    fn($p) => $this->serializer->serialize($p),
                    array_values($items)
                );

                $ok = $this->client->upsertProducts($batch, $apiKey, $apiUrl);
                if ($ok) {
                    $synced += count($batch);
                    $this->logger->info('IDEA89: synced batch', ['page' => $page, 'count' => count($batch)]);
                } else {
                    $failed += count($batch);
                    $this->logger->error('IDEA89: batch failed', ['page' => $page]);
                    // Every later batch would fail for the same reason.
                    if ($this->client->getSyncKeyRejection() !== null || $this->client->getConnectionError() !== null) {
                        break;
                    }
                }

                $page++;
            } while (count($items) === self::BATCH_SIZE);
        });

        if ($this->client->getSyncKeyRejection() !== null) {
            // Not a sync: leave "last synced" alone so the admin does not claim one happened.
            $this->logger->error('IDEA89: catalog sync refused', ['reason' => $this->client->getSyncKeyRejection()]);
            return ['synced' => $synced, 'failed' => $failed, 'withdrawn' => 0];
        }
        if ($this->client->getConnectionError() !== null) {
            // Nothing reached the API: same, "last synced" stays as it was.
            $this->logger->error('IDEA89: catalog sync could not reach the API', [
                'error' => $this->client->getConnectionError(),
            ]);
            return ['synced' => $synced, 'failed' => $failed, 'withdrawn' => 0];
        }

        $withdrawn = $this->withdrawHidden($apiKey, $apiUrl);

        $this->configWriter->save(self::XML_PATH_LAST_SYNC, (string) time());

        $this->logger->info('IDEA89: full sync complete', ['synced' => $synced, 'failed' => $failed, 'withdrawn' => $withdrawn]);
        return ['synced' => $synced, 'failed' => $failed, 'withdrawn' => $withdrawn];
    }

    /**
     * Sync a single product by ID. Used by the queue drain cron. A product
     * that is gone, disabled or not visible in the catalogue or search is
     * removed from the assistant instead (a configurable's children, which
     * are not visible on their own, live inside their parent).
     *
     * Returns false when the send failed in a way a retry can fix (API
     * unreachable, rate-limited or erroring), so the caller can queue the
     * product again; true when it was sent or a retry would not help.
     */
    public function syncProduct(int $productId): bool
    {
        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();

        if (!$apiKey) {
            return true;
        }

        $this->client->takeRetryableFailure();

        try {
            $this->inStoreView(function () use ($productId, $apiKey, $apiUrl): void {
                try {
                    $storeId = (int) $this->storeManager->getStore()->getId();
                    $product = $this->productRepository->getById($productId, false, $storeId, true);
                } catch (NoSuchEntityException $e) {
                    $this->client->deleteProducts([(string) $productId], $apiKey, $apiUrl);
                    return;
                }
                if ((int) $product->getStatus() !== Status::STATUS_ENABLED
                    || !in_array((int) $product->getVisibility(), self::VISIBLE, true)) {
                    $this->client->deleteProducts([(string) $productId], $apiKey, $apiUrl);
                    return;
                }
                $serialized = $this->serializer->serialize($product);
                $this->client->upsertProducts([$serialized], $apiKey, $apiUrl);
            });
        } catch (\Exception $e) {
            $this->logger->error('IDEA89: failed to sync product', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }

        return !$this->client->takeRetryableFailure();
    }

    /**
     * Remove every disabled or not-visible product from the assistant.
     * Idempotent: removing a product the assistant does not hold is a no-op.
     */
    public function withdrawHidden(string $apiKey, string $apiUrl): int
    {
        try {
            $disabled = $this->collectionFactory->create()
                ->addAttributeToFilter('status', ['neq' => Status::STATUS_ENABLED])
                ->getAllIds();
            $hidden = $this->collectionFactory->create()
                ->addAttributeToFilter('visibility', Visibility::VISIBILITY_NOT_VISIBLE)
                ->getAllIds();
            $ids = array_values(array_unique(array_map('strval', array_merge($disabled, $hidden))));
            if ($ids !== []) {
                $this->client->deleteProducts($ids, $apiKey, $apiUrl);
            }
            return count($ids);
        } catch (\Exception $e) {
            $this->logger->warning('IDEA89: could not withdraw hidden products', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Run in the default store view, so store-scoped values (option labels,
     * names, prices) are the storefront's, then restore the current store.
     */
    private function inStoreView(callable $fn): void
    {
        $previous = $this->storeManager->getStore()->getId();
        $default = $this->storeManager->getDefaultStoreView();
        if ($default !== null) {
            $this->storeManager->setCurrentStore($default->getId());
        }
        try {
            $fn();
        } finally {
            $this->storeManager->setCurrentStore($previous);
        }
    }
}
