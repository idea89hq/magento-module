<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Converts a Magento product into the JSON shape expected by POST /v1/catalog/upsert.
 */
class ProductSerializer
{
    private const XML_PATH_URL_SUFFIX = 'catalog/seo/product_url_suffix';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ResourceConnection $resourceConnection,
    ) {}

    public function serialize(ProductInterface $product): array
    {
        $store   = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        // Read the SEO URL suffix (typically ".html" or empty string)
        $suffix = (string) $this->scopeConfig->getValue(
            self::XML_PATH_URL_SUFFIX,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );

        /** @var \Magento\Catalog\Model\Product $product */
        // getFinalPrice() can return 0 for configurables when loaded via getList()
        // because price indexing isn't applied to collection results.
        // Try multiple sources: getFinalPrice → getPrice → getMinimalPrice → child prices.
        $price = $product->getFinalPrice();
        if ($price === null || (float) $price <= 0) {
            $price = $product->getPrice();
        }
        if ($price === null || (float) $price <= 0) {
            $price = $product->getMinimalPrice();
        }

        // Still 0? For configurables, get the minimum child price.
        if (($price === null || (float) $price <= 0) && $product->getTypeId() === 'configurable') {
            try {
                $children = $product->getTypeInstance()->getUsedProducts($product);
                $childPrices = [];
                foreach ($children as $child) {
                    $cp = $child->getFinalPrice() ?? $child->getPrice();
                    if ($cp !== null && (float) $cp > 0) {
                        $childPrices[] = (float) $cp;
                    }
                }
                if (!empty($childPrices)) {
                    $price = min($childPrices);
                }
            } catch (\Exception $e) {
                // Non-fatal — keep the 0 price rather than failing the sync
            }
        }

        // StockRegistryInterface works on both legacy and MSI installs.
        // On MSI stores, Magento's InventoryCatalog module keeps cataloginventory_stock_item
        // in sync, so getStockItem() always returns accurate data without coupling to MSI APIs.
        $stockItem = $this->stockRegistry->getStockItem((int) $product->getId());
        $qty       = $stockItem->getProductId() ? (int) $stockItem->getQty() : null;

        $categoryIds = [];
        if (method_exists($product, 'getCategoryIds')) {
            $categoryIds = $product->getCategoryIds();
        }

        $urlKey = ltrim((string) $product->getUrlKey(), '/');
        $url    = $baseUrl . '/' . $urlKey . $suffix;

        $productId = (int) $product->getId();
        $storeId   = (int) $store->getId();
        $reviews   = $this->fetchReviews($productId, $storeId);

        // is_new: true when current date falls within news_from_date / news_to_date
        $isNew = false;
        $newsFrom = $product->getData('news_from_date');
        if ($newsFrom) {
            $now      = new \DateTime();
            $fromDate = new \DateTime($newsFrom);
            $newsTo   = $product->getData('news_to_date');
            $isNew = $now >= $fromDate && ($newsTo === null || $now <= new \DateTime($newsTo));
        }

        // is_featured: opt-in custom attribute; absent/null → false
        $isFeatured = (bool) $product->getData('is_featured');

        return [
            'external_id'      => (string) $product->getId(),
            'product_type'     => (string) $product->getTypeId(),
            'sku'              => (string) $product->getSku(),
            'name'             => (string) $product->getName(),
            'description'      => strip_tags((string) ($product->getData('description') ?? '')),
            'price'            => $price !== null ? (float) $price : null,
            'currency'         => (string) ($store->getCurrentCurrencyCode() ?: 'GBP'),
            'in_stock'         => $stockItem->getProductId() ? (bool) $stockItem->getIsInStock() : true,
            'stock_qty'        => $qty,
            'url'              => $url,
            'image_url'        => $this->getImageUrl($product, $baseUrl),
            'category_path'    => implode(' > ', $categoryIds),
            'attributes'       => $this->extractAttributes($product),
            'variants'         => $this->extractVariants($product),
            'avg_rating'       => $reviews['avg_rating'],
            'review_count'     => $reviews['review_count'],
            'review_snippets'  => $reviews['review_snippets'],
            'is_new'           => $isNew,
            'is_featured'      => $isFeatured,
            'bestseller_rank'  => $this->fetchBestsellerRank($productId, $storeId),
        ];
    }

    private function getImageUrl(ProductInterface $product, string $baseUrl): ?string
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $image = $product->getData('thumbnail') ?? $product->getData('small_image');
        if (!$image || $image === 'no_selection') {
            return null;
        }
        return $baseUrl . '/media/catalog/product' . $image;
    }

    /**
     * Fetch avg_rating (0–5), review_count, and up to 3 approved review snippets
     * for the given product and store.
     *
     * Uses raw DB queries because Magento's review repository API does not expose
     * aggregated ratings per store without multiple round-trips.
     *
     * @return array{avg_rating: float|null, review_count: int, review_snippets: string[]}
     */
    private function fetchReviews(int $productId, int $storeId): array
    {
        $conn = $this->resourceConnection->getConnection();

        // Aggregated rating — percent is 0-100 (100 = 5 stars), divide by 20 to get 0-5.
        // Each rating type (e.g. "Quality", "Value") has its own row; average across them.
        $ratingRow = $conn->fetchRow(
            $conn->select()
                ->from(
                    $this->resourceConnection->getTableName('rating_option_vote_aggregated'),
                    [
                        'avg_percent' => new \Zend_Db_Expr('AVG(percent)'),
                        'total_count' => new \Zend_Db_Expr('MAX(vote_count)'),
                    ]
                )
                ->where('entity_pk_value = ?', $productId)
                ->where('store_id = ?', $storeId)
        );

        $avgRating   = null;
        $reviewCount = 0;

        if ($ratingRow && $ratingRow['total_count'] > 0) {
            $avgRating   = round((float) $ratingRow['avg_percent'] / 20, 2);
            $reviewCount = (int) $ratingRow['total_count'];
        }

        // Top 3 most recent approved review bodies (status_id = 2 means approved).
        $reviewDetailTable = $this->resourceConnection->getTableName('review_detail');
        $reviewTable       = $this->resourceConnection->getTableName('review');

        $snippetRows = $conn->fetchAll(
            $conn->select()
                ->from(['rd' => $reviewDetailTable], ['detail'])
                ->join(['r' => $reviewTable], 'r.review_id = rd.review_id', [])
                ->where('r.entity_pk_value = ?', $productId)
                ->where('r.status_id = ?', 2)  // 2 = approved
                ->where('rd.store_id = ?', $storeId)
                ->order('r.review_id DESC')
                ->limit(3)
        );

        $snippets = array_values(array_filter(
            array_map(
                fn(array $row): string => mb_substr(trim((string) $row['detail']), 0, 300),
                $snippetRows
            ),
            fn(string $s): bool => $s !== ''
        ));

        return [
            'avg_rating'      => $avgRating,
            'review_count'    => $reviewCount,
            'review_snippets' => $snippets,
        ];
    }

    /**
     * Return the most recent monthly bestseller rank for this product, or null if unavailable.
     * Uses Magento's sales_bestsellers_aggregated_monthly table (populated by the reports cron).
     * rating_pos = 1 means #1 bestseller that month.
     */
    private function fetchBestsellerRank(int $productId, int $storeId): ?int
    {
        $conn = $this->resourceConnection->getConnection();
        try {
            $table = $this->resourceConnection->getTableName('sales_bestsellers_aggregated_monthly');
            $row   = $conn->fetchRow(
                $conn->select()
                    ->from($table, ['rating_pos'])
                    ->where('product_id = ?', $productId)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->order('period DESC')
                    ->limit(1)
            );
            return ($row && isset($row['rating_pos']) && $row['rating_pos'] > 0)
                ? (int) $row['rating_pos']
                : null;
        } catch (\Exception $e) {
            // Table absent or reports not aggregated — non-fatal
            return null;
        }
    }

    /**
     * For configurable products, extract child SKU variant options (colour, size, etc.)
     * Returns an array of variant objects for the API.
     *
     * @return array<array{sku: string, color?: string, size?: string, in_stock: bool, price?: float, options: array<string, string>}>
     */
    private function extractVariants(ProductInterface $product): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return [];
        }

        try {
            /** @var Configurable $typeInstance */
            $typeInstance = $product->getTypeInstance();
            $children = $typeInstance->getUsedProducts($product);

            if (empty($children)) {
                return [];
            }

            // Get configurable attribute codes + IDs dynamically from the product's
            // super attributes — these are whatever the merchant configured
            // (could be color, size, material, length, width, anything).
            // We need both the human-readable label AND the Magento attribute/option IDs
            // for the widget's add-to-cart functionality.
            $configurableAttrs = [];    // [code => attributeId]
            $swatchData = [];           // [code => [optionId => {type, value}]]
            $configurableAttributes = $typeInstance->getConfigurableAttributes($product);
            foreach ($configurableAttributes as $attr) {
                $productAttr = $attr->getProductAttribute();
                if ($productAttr) {
                    $code = $productAttr->getAttributeCode();
                    if ($code) {
                        $configurableAttrs[$code] = (int) $productAttr->getId();
                        // Extract swatch data if available (color dots, images, text)
                        try {
                            $attrOptions = $productAttr->getSource()->getAllOptions(false);
                            foreach ($attrOptions as $opt) {
                                $optId = (string) ($opt['value'] ?? '');
                                if ($optId === '') continue;
                                $swatchData[$code][$optId] = null; // placeholder
                            }
                        } catch (\Exception $e) {
                            // Swatch extraction is best-effort
                        }
                    }
                }
            }
            // Load swatch values from the swatch resource if the module exists
            try {
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $swatchHelper = $objectManager->get(\Magento\Swatches\Helper\Data::class);
                foreach ($configurableAttrs as $attrCode => $attrId) {
                    if ($swatchHelper->isSwatchAttribute($objectManager->get(\Magento\Eav\Model\Config::class)->getAttribute('catalog_product', $attrCode))) {
                        $optionIds = array_keys($swatchData[$attrCode] ?? []);
                        if (!empty($optionIds)) {
                            $swatchCollection = $objectManager->create(\Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory::class)->create();
                            $swatchCollection->addFieldToFilter('option_id', ['in' => $optionIds]);
                            foreach ($swatchCollection as $swatch) {
                                $optId = (string) $swatch->getData('option_id');
                                $type = (int) $swatch->getData('type'); // 0=text, 1=color, 2=image
                                $val = (string) $swatch->getData('value');
                                $swatchData[$attrCode][$optId] = [
                                    'type' => $type === 1 ? 'color' : ($type === 2 ? 'image' : 'text'),
                                    'value' => $val,
                                ];
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Swatch module may not be installed — that's fine
            }
        } catch (\Exception $e) {
            return [];
        }

        $variants = [];
        foreach ($children as $child) {
            /** @var \Magento\Catalog\Model\Product $child */
            $childStock = $this->stockRegistry->getStockItem((int) $child->getId());

            $variant = [
                'sku'      => (string) $child->getSku(),
                'in_stock' => $childStock->getProductId() ? (bool) $childStock->getIsInStock() : true,
            ];

            $childPrice = $child->getFinalPrice() ?? $child->getPrice();
            if ($childPrice !== null) {
                $variant['price'] = (float) $childPrice;
            }

            // Extract ALL configurable option values dynamically.
            // Everything goes into the options map — no hardcoded field names.
            // Also extract super_attributes map for add-to-cart (attribute_id => option_id).
            $options = [];
            $superAttributes = [];
            foreach ($configurableAttrs as $attrCode => $attrId) {
                // getAttributeText resolves option IDs to human-readable labels
                $label = $child->getAttributeText($attrCode);
                if ($label === false || $label === null) {
                    $label = $child->getData($attrCode);
                }
                if ($label === null || $label === '' || $label === false) {
                    continue;
                }
                $strLabel = is_array($label) ? implode(', ', $label) : (string) $label;
                $options[$attrCode] = $strLabel;

                // Get the raw option ID (integer) for Magento's super_attribute format
                $rawOptionId = $child->getData($attrCode);
                if ($rawOptionId !== null && $rawOptionId !== '' && is_numeric($rawOptionId)) {
                    $superAttributes[(string) $attrId] = (string) $rawOptionId;
                    // Attach swatch data if available for this option
                    if (isset($swatchData[$attrCode][(string) $rawOptionId])) {
                        $sw = $swatchData[$attrCode][(string) $rawOptionId];
                        if ($sw !== null) {
                            if (!isset($variant['swatches'])) {
                                $variant['swatches'] = [];
                            }
                            $variant['swatches'][$attrCode] = $sw;
                        }
                    }
                }
            }
            if (!empty($options)) {
                $variant['options'] = $options;
            }
            if (!empty($superAttributes)) {
                $variant['super_attributes'] = $superAttributes;
            }

            $variants[] = $variant;
        }

        return $variants;
    }

    private function extractAttributes(ProductInterface $product): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $attrs = [];
        foreach ($product->getCustomAttributes() as $attr) {
            $attrCode = $attr->getAttributeCode();
            try {
                $attrModel = $this->attributeRepository->get($attrCode);
            } catch (\Exception $e) {
                continue;
            }
            // Only include searchable or filterable attributes
            if (!$attrModel->getIsSearchable() && !$attrModel->getIsFilterable()) {
                continue;
            }
            // Prefer human-readable label (resolves option IDs to text)
            $value = $product->getAttributeText($attrCode);
            if ($value === false || $value === null) {
                $value = $attr->getValue();
            }
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, fn($v) => $v !== '' && $v !== false));
            }
            if ((string) $value !== '') {
                $attrs[$attrCode] = (string) $value;
            }
        }
        return $attrs;
    }
}
