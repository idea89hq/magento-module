<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Converts a Magento product into the JSON shape expected by POST /v1/catalog/upsert.
 *
 * Schema 2 (module 1.4.0): alongside the schema-1 fields every IDEA89 API
 * version accepts, a product carries its attribute list (labels, types,
 * option labels, flags), short description, category paths as names, tier
 * prices for any shopper, and the price's tax basis and rate; each
 * configurable child carries its stock quantity and the attribute values it
 * does not share with its parent. An API older than schema 2 ignores the new
 * keys and keeps reading the schema-1 ones.
 */
class ProductSerializer
{
    private const XML_PATH_URL_SUFFIX = 'catalog/seo/product_url_suffix';

    /**
     * Per-instance category-name cache. Magento sync runs serialize() once per
     * product; categories repeat constantly (Living, Kitchen, etc.). One DB
     * round-trip per ID per process is enough — Magento's CategoryRepository
     * caches under the hood too, but keeping a local map avoids the
     * per-product NoSuchEntity try/catch overhead.
     *
     * @var array<int, string|null> ID → lowercase trimmed name (null on miss)
     */
    private array $categoryNameCache = [];

    /** @var array<int, string|null> ID → category path as names ("Garden > Benches"), null on miss */
    private array $categoryPathCache = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly AttributeExtractor $attributeExtractor,
        private readonly PriceResolver $priceResolver,
        private readonly StockResolver $stockResolver,
    ) {}

    public function serialize(ProductInterface $product): array
    {
        $store   = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        $price = $this->shopperPrice($product, $store);

        // Salable stock: with MSI, reservations from orders are taken off
        // (StockResolver); without it, the legacy stock item.
        $stock = $this->stockResolver->stockFor((int) $product->getId(), (string) $product->getSku(), (int) $store->getWebsiteId());

        $categoryIds = [];
        if (method_exists($product, 'getCategoryIds')) {
            $categoryIds = $product->getCategoryIds();
        }
        $categoryNames = $this->resolveCategoryNames($categoryIds);
        $categoryPaths = $this->resolveCategoryPaths($categoryIds, (int) $store->getRootCategoryId());
        $attributeList = $this->attributeExtractor->extract($product, (int) $store->getId());
        // Every ancestor's name too, as the comment on category_names below
        // has always said: "living" matches a product filed under Living > Rugs.
        foreach ($categoryPaths as $path) {
            foreach (explode(' > ', $path) as $n) {
                $n = trim(mb_strtolower($n));
                if ($n !== '' && !in_array($n, $categoryNames, true)) {
                    $categoryNames[] = $n;
                }
            }
        }
        $categoryNames = array_slice(array_values(array_filter($categoryNames, static fn (string $n): bool => mb_strlen($n) <= 64)), 0, 20);

        $url = $this->getProductUrl($product, $baseUrl);

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
            'description'      => $this->getDescription($product),
            'price'            => $price !== null ? (float) $price : null,
            'currency'         => (string) ($store->getCurrentCurrencyCode() ?: 'GBP'),
            'in_stock'         => $stock['in_stock'],
            'stock_qty'        => $stock['qty'],
            'url'              => $url,
            'image_url'        => $this->getImageUrl($product, $baseUrl),
            'category_path'    => implode(' > ', $categoryIds),
            // Lowercase trimmed category NAMES the product belongs to. The API
            // filters per-row on these (see migration 0047 + retrieval.ts
            // WHERE_CLAUSE). Without this field the API falls back to the
            // legacy split_part on category_path, which doesn't parse
            // Magento's ID-joined-by-`>` shape — silent no-op. Send every
            // ancestor name so "cheapest in living" matches whether the
            // product sits in Living, Living/Cushions, or Home/Living/Rugs.
            'category_names'   => $categoryNames,
            // Schema 2: the full paths as names, e.g. "Garden > Furniture > Benches".
            'category_paths'   => $categoryPaths,
            // Schema 1 map (every API reads it) and the schema-2 list.
            'attributes'       => $this->attributeExtractor->flatMap($attributeList),
            'attribute_list'   => $attributeList,
            'short_description' => $this->getShortDescription($product),
            'price_includes_tax' => $this->priceResolver->pricesIncludeTax($store),
            'tax_rate'         => $this->priceResolver->taxRate($product, $store),
            'tier_prices'      => $this->priceResolver->tierPrices($product, $price !== null ? (float) $price : null),
            'variants'         => $this->extractVariants($product, $attributeList, $store),
            'avg_rating'       => $reviews['avg_rating'],
            'review_count'     => $reviews['review_count'],
            'review_snippets'  => $reviews['review_snippets'],
            'is_new'           => $isNew,
            'is_featured'      => $isFeatured,
            'bestseller_rank'  => $this->fetchBestsellerRank($productId, $storeId),
            // Sale signal. special_price + special_from_date/special_to_date
            // are Magento's native sale-pricing columns. is_on_sale is TRUE
            // when special_price is set AND today's date falls within the
            // window (either bound can be null = open-ended). Mirrors the
            // is_new computation a few lines above.
            'sale_price'       => $this->fetchSalePrice($product),
            'is_on_sale'       => $this->isOnSale($product),
        ];
    }

    /**
     * The price a logged-out shopper pays, on the basis price_includes_tax
     * describes: catalogue rules applied, a configurable priced from its
     * children. The sync and the live endpoint both use it.
     */
    public function shopperPrice(ProductInterface $product, \Magento\Store\Api\Data\StoreInterface $store): ?float
    {
        /** @var \Magento\Catalog\Model\Product $product */
        // A configurable's own getFinalPrice() is the storefront display
        // amount: with prices shown including tax it has the tax added, while
        // price_includes_tax says how prices are entered, so a price entered
        // ex-tax went out with the tax on and still marked ex-tax. Its price
        // is its cheapest child's, on the same basis as a simple product's.
        $cheapestChild = $product->getTypeId() === 'configurable' ? $this->cheapestChildPrice($product, $store) : null;

        // getFinalPrice() can return 0 for configurables when loaded via getList()
        // because price indexing isn't applied to collection results.
        // Try multiple sources: getFinalPrice → getPrice → getMinimalPrice → child prices.
        $price = $cheapestChild ?? $product->getFinalPrice();
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

        // A catalogue price rule is not applied by getFinalPrice() outside the
        // storefront (cron, CLI): the logged-out shopper's rule price is read
        // from the rule index (PriceResolver).
        if ($cheapestChild === null) {
            $price = $this->priceResolver->shopperPrice($price !== null ? (float) $price : null, $product, $store);
        }

        return $price !== null ? (float) $price : null;
    }

    /**
     * The lowest price a logged-out shopper pays for one of a configurable's
     * children (catalogue rules applied), counting children that can be
     * bought when there are any, as the storefront's "from" price does.
     */
    private function cheapestChildPrice(ProductInterface $product, \Magento\Store\Api\Data\StoreInterface $store): ?float
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $salable = [];
            $all = [];
            foreach ($product->getTypeInstance()->getUsedProducts($product) as $child) {
                $own = $child->getFinalPrice() ?? $child->getPrice();
                $paid = $this->priceResolver->shopperPrice($own !== null ? (float) $own : null, $child, $store);
                if ($paid === null || $paid <= 0) {
                    continue;
                }
                $all[] = $paid;
                if ($child->isSalable()) {
                    $salable[] = $paid;
                }
            }
            $prices = $salable !== [] ? $salable : $all;
            return $prices !== [] ? min($prices) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Return the Magento special_price for this product, or null if the
     * special-price window is not currently active.
     */
    private function fetchSalePrice(ProductInterface $product): ?float
    {
        if (! $this->isOnSale($product)) {
            return null;
        }
        $sale = $product->getData('special_price');
        return ($sale !== null && (float) $sale > 0) ? (float) $sale : null;
    }

    /**
     * is_on_sale: TRUE when special_price is set AND today falls within
     * special_from_date / special_to_date (either bound may be null =
     * open-ended).
     */
    private function isOnSale(ProductInterface $product): bool
    {
        $sale = $product->getData('special_price');
        if ($sale === null || (float) $sale <= 0) {
            return false;
        }
        $now    = new \DateTime();
        $fromOk = true;
        $toOk   = true;
        if ($from = $product->getData('special_from_date')) {
            $fromOk = $now >= new \DateTime($from);
        }
        if ($to = $product->getData('special_to_date')) {
            $toOk = $now <= new \DateTime($to);
        }
        return $fromOk && $toOk;
    }

    /**
     * Resolve a list of Magento category IDs to lowercase trimmed names,
     * deduped and sorted, ready to land in the API's `category_slugs TEXT[]`
     * column. Skips the root category (Magento default ID 1) because every
     * tree includes it and shippers don't say "I want something in 'default
     * category'". Misses (deleted categories) drop silently.
     *
     * @param int[]|string[] $categoryIds
     * @return string[]
     */
    private function resolveCategoryNames(array $categoryIds): array
    {
        $names = [];
        foreach ($categoryIds as $id) {
            $intId = (int) $id;
            // Root category — Magento ships with ID 1 ("Default Category")
            // at the top of every tree. Filtering by "default category" is
            // meaningless to the shopper, so skip it.
            if ($intId <= 1) {
                continue;
            }
            if (!array_key_exists($intId, $this->categoryNameCache)) {
                try {
                    $category = $this->categoryRepository->get($intId);
                    $rawName  = (string) $category->getName();
                    $clean    = trim(mb_strtolower($rawName));
                    $this->categoryNameCache[$intId] = $clean !== '' ? $clean : null;
                } catch (NoSuchEntityException $e) {
                    $this->categoryNameCache[$intId] = null;
                }
            }
            $name = $this->categoryNameCache[$intId];
            if ($name !== null) {
                $names[$name] = true;
            }
        }
        $list = array_keys($names);
        sort($list);
        return $list;
    }

    /**
     * Public so other read-only surfaces that describe this same catalog to a
     * third party (Model/Acp/FeedBuilder.php) reuse this exact resolution
     * instead of deriving image URLs a second way.
     */
    public function getImageUrl(ProductInterface $product, string $baseUrl): ?string
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $image = $product->getData('thumbnail') ?? $product->getData('small_image');
        if (!$image || $image === 'no_selection') {
            return null;
        }
        return $baseUrl . '/media/catalog/product' . $image;
    }

    /**
     * Public for the same reason as getImageUrl() above — Model/Acp/FeedBuilder.php
     * reuses this rather than re-deriving the SEO URL shape.
     */
    public function getProductUrl(ProductInterface $product, string $baseUrl): string
    {
        $suffix = (string) $this->scopeConfig->getValue(
            self::XML_PATH_URL_SUFFIX,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
        $urlKey = ltrim((string) $product->getUrlKey(), '/');
        return $baseUrl . '/' . $urlKey . $suffix;
    }

    /**
     * Public for the same reason as getImageUrl() above — Model/Acp/FeedBuilder.php
     * reuses this rather than re-deriving the description field.
     */
    public function getDescription(ProductInterface $product): string
    {
        return $this->htmlToText((string) ($product->getData('description') ?? ''));
    }

    public function getShortDescription(ProductInterface $product): ?string
    {
        $text = $this->htmlToText((string) ($product->getData('short_description') ?? ''));
        return $text === '' ? null : $text;
    }

    /**
     * Plain text from product HTML, one line per block. strip_tags() alone
     * joined paragraphs and list items ("…with it.100% linen, 250 gsm50cm"),
     * so the end of a block becomes a line break first. Page Builder's HTML
     * Code element stores its markup escaped ("&lt;P&gt;"), so what decodes
     * to tags is converted once more.
     */
    private function htmlToText(string $html, bool $again = true): string
    {
        $html = (string) preg_replace('#<(?:br|hr)\b[^>]*>|</(?:p|div|li|tr|h[1-6]|ul|ol|table|section|article|blockquote)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($again && preg_match('#</?[a-z][a-z0-9]*\b[^>]*>#i', $text)) {
            return $this->htmlToText($text, false);
        }
        $lines = array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));
        return trim(implode("\n", array_values(array_filter($lines, static fn (string $l): bool => $l !== ''))));
    }

    /**
     * Each assigned category as its path of names below the store's root
     * ("Garden > Furniture > Benches"), deduped. Categories outside the
     * store's tree are skipped.
     *
     * @param int[]|string[] $categoryIds
     * @return string[]
     */
    private function resolveCategoryPaths(array $categoryIds, int $rootId): array
    {
        $paths = [];
        foreach ($categoryIds as $id) {
            $intId = (int) $id;
            if ($intId <= 1) {
                continue;
            }
            if (!array_key_exists($intId, $this->categoryPathCache)) {
                $this->categoryPathCache[$intId] = null;
                try {
                    $ids = array_map('intval', explode('/', (string) $this->categoryRepository->get($intId)->getPath()));
                    $at = array_search($rootId, $ids, true);
                    if ($rootId > 0 && $at !== false) {
                        $names = [];
                        foreach (array_slice($ids, $at + 1) as $cid) {
                            $names[] = trim((string) $this->categoryRepository->get($cid)->getName());
                        }
                        $names = array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
                        $this->categoryPathCache[$intId] = $names === [] ? null : implode(' > ', $names);
                    }
                } catch (NoSuchEntityException $e) {
                    $this->categoryPathCache[$intId] = null;
                }
            }
            if ($this->categoryPathCache[$intId] !== null) {
                $paths[$this->categoryPathCache[$intId]] = true;
            }
        }
        return array_keys($paths);
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
    private function extractVariants(ProductInterface $product, array $parentAttributes = [], ?\Magento\Store\Api\Data\StoreInterface $store = null): array
    {
        $store ??= $this->storeManager->getStore();
        $parentValues = [];
        foreach ($parentAttributes as $a) {
            $parentValues[$a['code']] = $a['value'];
        }
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
            $optionLabels = [];         // [code => the label shoppers see]
            $swatchData = [];           // [code => [optionId => {type, value}]]
            $configurableAttributes = $typeInstance->getConfigurableAttributes($product);
            foreach ($configurableAttributes as $attr) {
                $productAttr = $attr->getProductAttribute();
                if ($productAttr) {
                    $code = $productAttr->getAttributeCode();
                    if ($code) {
                        $configurableAttrs[$code] = (int) $productAttr->getId();
                        // The option's label as the product page shows it; a
                        // code need not say what it is ("..._chair_option" is
                        // the heat and massage option).
                        $optionLabel = trim((string) $attr->getLabel());
                        if ($optionLabel === '') {
                            $optionLabel = trim((string) $productAttr->getStoreLabel((int) $store->getId()));
                        }
                        if ($optionLabel === '') {
                            $optionLabel = trim((string) $productAttr->getDefaultFrontendLabel());
                        }
                        if ($optionLabel !== '') {
                            $optionLabels[$code] = $optionLabel;
                        }
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
            $childStock = $this->stockResolver->stockFor((int) $child->getId(), (string) $child->getSku(), (int) $store->getWebsiteId());

            $variant = [
                'sku'       => (string) $child->getSku(),
                'name'      => (string) $child->getName(),
                'in_stock'  => $childStock['in_stock'],
                // Schema 2: the child's own salable quantity.
                'stock_qty' => $childStock['qty'],
            ];

            $childPrice = $child->getFinalPrice() ?? $child->getPrice();
            $childPrice = $this->priceResolver->shopperPrice($childPrice !== null ? (float) $childPrice : null, $child, $store);
            if ($childPrice !== null) {
                $variant['price'] = (float) $childPrice;
            }

            // Schema 2: the child's attribute values that differ from the parent's.
            $own = [];
            foreach ($this->attributeExtractor->extract($child, (int) $store->getId()) as $a) {
                if (!array_key_exists($a['code'], $parentValues) || $parentValues[$a['code']] !== $a['value']) {
                    $own[] = $a;
                }
            }
            if ($own !== []) {
                $variant['attribute_list'] = $own;
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
                // Schema 2: the store's label for each option code.
                $labels = array_intersect_key($optionLabels, $options);
                if ($labels !== []) {
                    $variant['option_labels'] = $labels;
                }
            }
            if (!empty($superAttributes)) {
                $variant['super_attributes'] = $superAttributes;
            }

            $variants[] = $variant;
        }

        return $variants;
    }
}
