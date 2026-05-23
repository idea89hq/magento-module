<?php

declare(strict_types=1);

namespace Idea89\Assistant\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;

/**
 * Pushes stock qty + in_stock to the API whenever a stock item is saved.
 *
 * Fires on cataloginventory_stock_item_save_after, which covers:
 *  - Order placement (qty decrement)
 *  - Admin manual qty / status edit
 *  - Import
 *  - MSI stores: Magento's InventoryCatalog module saves to the legacy stock item
 *    table after any MSI source-item change, so this observer fires there too.
 *
 * Both qty and is_in_stock are captured — is_in_stock can change independently
 * of qty (manual status override, backorder threshold, etc.).
 */
class StockItemSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config        $config,
        private readonly Idea89Client  $client,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();
        if (!$apiKey || !$apiUrl) {
            return;
        }

        /** @var \Magento\CatalogInventory\Model\Stock\Item $item */
        $item = $observer->getEvent()->getItem();
        if (!$item || !$item->getProductId()) {
            return;
        }

        $payload = [[
            'external_id' => (string) $item->getProductId(),
            'in_stock'    => (bool) $item->getIsInStock(),
            'stock_qty'   => (int) $item->getQty(),
        ]];

        $result = $this->client->upsertStock($payload, $apiKey, $apiUrl);
        if (!$result) {
            $this->logger->error('IDEA89: failed to sync stock for product ' . $item->getProductId());
        }
    }
}
