<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;

class CatalogSyncer
{
    private const BATCH_SIZE = 100;
    private const XML_PATH_LAST_SYNC = 'idea89/sync/last_full_sync_at';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ProductSerializer $serializer,
        private readonly Idea89Client $client,
        private readonly Config $config,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Full catalog sync — batches of 100, logs progress.
     * Called by the DailySync cron and the "Sync Now" admin button.
     */
    public function syncAll(?string $storeCode = null): void
    {
        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();

        if (!$apiKey) {
            $this->logger->warning('IDEA89: syncAll skipped — no API key configured');
            return;
        }

        $this->logger->info('IDEA89: starting full catalog sync');

        $page = 1;
        $synced = 0;
        $failed = 0;

        do {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
                ->addFilter('visibility', [
                    Visibility::VISIBILITY_IN_CATALOG,
                    Visibility::VISIBILITY_IN_SEARCH,
                    Visibility::VISIBILITY_BOTH,
                ], 'in')
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
            }

            $page++;
        } while (count($items) === self::BATCH_SIZE);

        $this->configWriter->save(self::XML_PATH_LAST_SYNC, (string) time());

        $this->logger->info('IDEA89: full sync complete', ['synced' => $synced, 'failed' => $failed]);
    }

    /**
     * Sync a single product by ID. Used by the queue drain cron.
     */
    public function syncProduct(int $productId): void
    {
        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();

        if (!$apiKey) {
            return;
        }

        try {
            $product = $this->productRepository->getById($productId, false, 0, true);
            $serialized = $this->serializer->serialize($product);
            $this->client->upsertProducts([$serialized], $apiKey, $apiUrl);
        } catch (\Exception $e) {
            $this->logger->error('IDEA89: failed to sync product', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
