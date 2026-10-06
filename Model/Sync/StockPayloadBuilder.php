<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stock items for POST /v1/catalog/stock.
 *
 * A configurable child is sent twice: as itself (schema 1, which every API
 * version reads) and as a variant of each parent, with the parent's id and
 * the child's SKU (schema 2), because the API holds variants inside the
 * parent's row. Quantities are salable quantities (StockResolver).
 */
class StockPayloadBuilder
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ConfigurableResource $configurableResource,
        private readonly StockResolver $stockResolver,
        private readonly StoreManagerInterface $storeManager,
    ) {}

    /**
     * @param int[] $productIds
     * @return array<int, array<string, mixed>>
     */
    public function build(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }
        $skus = [];
        $collection = $this->collectionFactory->create()->addFieldToFilter('entity_id', ['in' => $productIds]);
        foreach ($collection as $p) {
            $skus[(int) $p->getId()] = (string) $p->getSku();
        }
        $websiteId = (int) $this->storeManager->getDefaultStoreView()->getWebsiteId();
        $items = [];
        foreach ($productIds as $id) {
            $sku = $skus[$id] ?? '';
            $stock = $this->stockResolver->stockFor($id, $sku, $websiteId);
            $items[] = ['external_id' => (string) $id, 'in_stock' => $stock['in_stock'], 'stock_qty' => $stock['qty']];
            if ($sku === '') {
                continue;
            }
            foreach ($this->configurableResource->getParentIdsByChild($id) as $parentId) {
                $items[] = [
                    'external_id'        => (string) $id,
                    'parent_external_id' => (string) $parentId,
                    'sku'                => $sku,
                    'in_stock'           => $stock['in_stock'],
                    'stock_qty'          => $stock['qty'],
                ];
            }
        }
        return $items;
    }
}
