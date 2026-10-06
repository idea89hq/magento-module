<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stock as the storefront sells it.
 *
 * Without multi-source inventory (MSI) the legacy stock item is the truth.
 * With MSI, an order places a reservation and touches neither the source
 * items nor the legacy stock item, so the legacy quantity stays high until
 * the shipment deducts it. The salable quantity (source quantity minus
 * reservations, for the store's website stock) is what a shopper can buy;
 * it is read through MSI's own service when MSI is enabled.
 *
 * The MSI services are resolved at run time, through the object manager, so
 * that stores which remove the Inventory modules (a common composer
 * "replace") still compile; nothing here runs unless MSI is enabled.
 */
class StockResolver
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
        private readonly StoreManagerInterface $storeManager,
    ) {}

    /**
     * @return array{in_stock: bool, qty: int|null}
     */
    public function stockFor(int $productId, string $sku, ?int $websiteId = null): array
    {
        $item = $this->stockRegistry->getStockItem($productId);
        $legacy = [
            'in_stock' => $item->getProductId() ? (bool) $item->getIsInStock() : true,
            'qty'      => $item->getProductId() ? (int) $item->getQty() : null,
        ];
        if ($sku === '' || !$this->moduleManager->isEnabled('Magento_InventorySalesApi')) {
            return $legacy;
        }
        try {
            $websiteCode = $this->storeManager->getWebsite($websiteId)->getCode();
            /** @var \Magento\InventorySalesApi\Api\StockResolverInterface $resolver */
            $resolver = $this->objectManager->get('Magento\InventorySalesApi\Api\StockResolverInterface');
            $stockId = (int) $resolver->execute('website', $websiteCode)->getStockId();
            /** @var \Magento\InventorySalesApi\Api\IsProductSalableInterface $isSalable */
            $isSalable = $this->objectManager->get('Magento\InventorySalesApi\Api\IsProductSalableInterface');
            $salable = (bool) $isSalable->execute($sku, $stockId);
            $qty = null;
            if ($item->getProductId() && $item->getManageStock()) {
                /** @var \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface $getQty */
                $getQty = $this->objectManager->get('Magento\InventorySalesApi\Api\GetProductSalableQtyInterface');
                $qty = (int) $getQty->execute($sku, $stockId);
            }
            return ['in_stock' => $salable, 'qty' => $qty];
        } catch (\Throwable $e) {
            // Products of a type without stock, or a stock MSI cannot resolve.
            return $legacy;
        }
    }
}
