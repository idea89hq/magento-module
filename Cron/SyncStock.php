<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Cron;

use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;
use Magento\Framework\App\ResourceConnection;

/**
 * Pushes stock qty + in_stock for every product every minute.
 * Uses the lightweight /v1/catalog/stock endpoint — does not re-embed.
 *
 * Uses ResourceConnection (direct SQL) instead of a collection factory to avoid
 * DB connection config issues that can affect CatalogInventory resource models.
 */
class SyncStock
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly Config             $config,
        private readonly Idea89Client       $client,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface    $logger
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();
        if (!$apiKey || !$apiUrl) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('cataloginventory_stock_item');

        // stock_id = 1 is the default stock, present on all Magento 2 installs.
        // On MSI stores, Magento's InventoryCatalog module keeps this table in sync.
        $select = $connection->select()
            ->from($table, ['product_id', 'qty', 'is_in_stock'])
            ->where('stock_id = ?', 1);

        $rows   = $connection->fetchAll($select);
        $batch  = [];
        $synced = 0;

        foreach ($rows as $row) {
            $batch[] = [
                'external_id' => (string) $row['product_id'],
                'in_stock'    => (bool) $row['is_in_stock'],
                'stock_qty'   => (int) $row['qty'],
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                if ($this->client->upsertStock($batch, $apiKey, $apiUrl)) {
                    $synced += count($batch);
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            if ($this->client->upsertStock($batch, $apiKey, $apiUrl)) {
                $synced += count($batch);
            }
        }

        $this->logger->info('IDEA89: stock sync complete', ['count' => $synced]);
    }
}
