<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Acp;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Idea89\Assistant\Model\Sync\ProductSerializer;
use Psr\Log\LoggerInterface;

/**
 * Builds the ACP product feed payload — the three gates from
 * Controller/Product/Mini.php (enabled, individually visible, assigned to
 * the current website), enforced as DB-level collection filters rather than
 * post-load in PHP, so `total` in the response is the real, DB-computed
 * count of a page-clamped query.
 *
 * Field selection for the parts that overlap with the existing catalog
 * sync payload (description, image link, product link) is reused from
 * Model/Sync/ProductSerializer.php rather than re-derived here — see the
 * three public getters that class gained for this. Price is deliberately
 * NOT reused from there: the sync payload's serialize() runs a multi-step
 * fallback (final → price → minimal → cheapest child) to avoid a zero
 * price reaching the shopper-facing widget, but task-4.3-brief.md is
 * explicit that this feed reads getFinalPrice() only, in the current store
 * scope — see finalPrice() below for how that single method is kept
 * correct for a configurable parent without adding the fallback chain the
 * brief rules out, and for why the obvious fix (joining the collection to
 * catalog_product_index_price via addFinalPrice()) was tried, found to
 * silently drop every non-price-indexed product — including every
 * out-of-stock one — from `products`/`total`, and reverted. Full account,
 * with the live counts, in the task report.
 *
 * "Individually visible" here mirrors Mini.php's actual gate
 * (ProductHelper::canShow(), which is enabled + visibility !=
 * VISIBILITY_NOT_VISIBLE) rather than also excluding Search-only products.
 * A Search-only product still has a working, individually addressable PDP
 * — unlike a configurable's child simple, which does not — so it is not
 * the "child SKU an agent could try to buy" risk the brief's second gate
 * is about. See the task report for the full reasoning.
 *
 * DEVIATION FROM task-4.3-brief.md's interface list: built on
 * Magento\Catalog\Model\ResourceModel\Product\CollectionFactory rather than
 * ProductRepositoryInterface::getList() + SearchCriteriaBuilder. Discovered
 * live (see the task report): Magento\CatalogInventory\Model
 * \AddStockStatusToCollection is a stock `frontend`-area plugin on
 * Collection::load() that silently drops out-of-stock products from EVERY
 * frontend product collection — including the one ProductRepository builds
 * internally — whenever "Display Out of Stock Products" is off (the
 * default). That directly breaks this task's own hard requirement ("an
 * out-of-stock product appears... rather than being dropped"), and
 * SearchCriteriaBuilder has no way to reach the collection ProductRepository
 * builds internally to suppress it. The collection exposes the documented
 * escape hatch instead: setFlag('has_stock_status_filter', true) before
 * load() — see build() below. ProductRepositoryInterface is still used for
 * the item_group_id parent lookup, where this plugin does not apply
 * (Product::load(), not Collection::load()).
 *
 * A product whose price (after finalPrice()'s retry) is still <= 0 is
 * skipped entirely rather than published at "0.00" — fix-round-2 ruling,
 * see build()'s inline comment on the skip for the full reasoning and its
 * effect on `total`.
 */
class FeedBuilder
{
    public const MAX_PAGE_SIZE = 200;
    public const DEFAULT_PAGE_SIZE = 200;

    private const XML_PATH_STORE_NAME = 'general/store_information/name';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductSerializer $productSerializer,
        private readonly Configurable $configurableType,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     version: string,
     *     seller: array{name: string, url: string},
     *     page: int, page_size: int, total: int,
     *     products: array<int, array<string, mixed>>
     * }
     */
    public function build(int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));

        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $websiteId = (int) $store->getWebsiteId();

        /** @var Collection $collection */
        $collection = $this->productCollectionFactory->create();
        // Store scope FIRST: status/visibility below resolve against
        // whatever store the collection is currently scoped to, and
        // addStoreFilter() is what sets that (plus applies the matching
        // product-limitation website join) — same ordering the stock
        // storefront category listing block uses.
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);
        $collection->addWebsiteFilter($websiteId);
        // NOTE (fix-round-2): deliberately NOT calling addFinalPrice() here
        // — see the class docblock's price paragraph for why that join was
        // tried and reverted. Price correctness for a configurable is
        // instead handled per-item in serializeProduct(), which cannot
        // affect which rows reach `products`/`total`.
        //
        // See the class docblock: this is the documented escape hatch from
        // Magento\CatalogInventory\Model\AddStockStatusToCollection, a
        // frontend-area plugin that otherwise silently drops every
        // out-of-stock product from this collection's load() — which would
        // violate this task's explicit "out-of-stock products appear
        // rather than being dropped" requirement before serializeProduct()
        // ever got a chance to report their real availability.
        $collection->setFlag('has_stock_status_filter', true);
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($page);

        $total = (int) $collection->getSize();

        // Belt-and-suspenders: don't trust the collection's own LIMIT/OFFSET
        // to come back empty just because $page landed past the last real
        // page. Observed live during this task's manual testing: an
        // out-of-range page number returned page 1's rows instead of zero
        // rows, which would leak page 1's products back out under whatever
        // huge page number was requested. The exact mechanism was NOT
        // isolated (traced pagination through Zend_Db_Select::limitPage(),
        // Collection::getCurPage()/getLastPageNumber(), _renderLimit(), and
        // PaginationProcessor — nothing there clamps a page number, and the
        // likelier explanation is that the reproduction script reused one
        // already-loaded collection object across a page loop, where
        // Collection::load() no-ops on a second call — a script artefact,
        // not necessarily a production code path — see the task report for
        // the full account). The guard's outcome is correct regardless of
        // cause and is cheap insurance either way, so it stays: compute the
        // real last page ourselves from `total` and refuse to serialise
        // anything once $page is past it, regardless of what the collection
        // handed back.
        $lastPage = $total > 0 ? (int) ceil($total / $pageSize) : 1;

        // Fix-round-2 ruling: a product with no resolvable price is not
        // usable data for an agent — publishing it as free (an agent that
        // reads $0.00 can present it to a shopper as free, and a Task 4.4
        // checkout session could then try to transact at that price) is
        // strictly worse than omitting it, so it is skipped here rather
        // than emitted. Applied AFTER finalPrice()'s configurable retry —
        // computed once per item and reused in serializeProduct() below —
        // so a configurable the retry rescues still publishes; only a
        // product whose price is STILL <= 0 once the retry has had its
        // chance gets skipped. `<= 0.0`, not `=== 0.0`: a negative value
        // would never legitimately occur but would be worse to publish
        // than zero, not merely equal to it. A genuinely free (£0.00)
        // product is also omitted by this rule — a real but accepted cost;
        // ACP has no clean semantics for a free item either, and omission
        // is the safer failure direction than publishing a wrong price.
        //
        // `total` is adjusted down by the number skipped on THIS page so it
        // matches what `products` (across every page) will actually
        // contain, per fix-round-2's reconciliation requirement — exact
        // for any catalog whose whole gated result fits in one page (this
        // task's 181-product test catalog does, at the 200 cap), but only a
        // same-page best-effort for a catalog that spans multiple pages,
        // since computing a truly global zero-price count would mean
        // pricing every gated product up front on every request rather than
        // just the current page's slice. Documented, not solved, here.
        $products = [];
        $skippedZeroPrice = 0;
        if ($page <= $lastPage) {
            foreach ($collection->getItems() as $product) {
                /** @var ProductInterface $product */
                $price = $this->finalPrice($product, $storeId);
                if ($price <= 0.0) {
                    $skippedZeroPrice++;
                    $this->logger->info(
                        '[idea89] ACP feed: omitting product with no resolvable price',
                        ['sku' => (string) $product->getSku()]
                    );
                    continue;
                }
                $products[] = $this->serializeProduct($product, $store, $storeId, $websiteId, $price);
            }
        }

        return [
            'version' => Auth::SUPPORTED_API_VERSIONS[0],
            'seller' => [
                'name' => $this->sellerName($storeId),
                'url' => rtrim((string) $store->getBaseUrl(), '/'),
            ],
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total - $skippedZeroPrice,
            'products' => $products,
        ];
    }

    /**
     * getBaseUrl()/getCurrentCurrencyCode() are not declared on StoreInterface
     * (only id/code/name are) but exist on the concrete
     * \Magento\Store\Model\Store every StoreManagerInterface::getStore() call
     * returns at runtime — the same duck-typed convention Mini.php already
     * relies on for getWebsiteId().
     *
     * $price is computed once by the caller (build()'s loop, via
     * finalPrice()) rather than recomputed here — the caller needs the
     * value BEFORE this method runs, to decide whether to skip a
     * zero-priced product instead of calling this at all (see build()'s
     * docblock comment on that skip).
     */
    private function serializeProduct(
        ProductInterface $product,
        StoreInterface $store,
        int $storeId,
        int $websiteId,
        float $price
    ): array {
        /** @var \Magento\Catalog\Model\Product $product */
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        $stockItem = $this->stockRegistry->getStockItem((int) $product->getId());
        $inStock = $stockItem->getProductId() ? (bool) $stockItem->getIsInStock() : true;
        $qty = $stockItem->getProductId() ? (int) $stockItem->getQty() : 0;

        $item = [
            'id' => (string) $product->getSku(),
            'title' => (string) $product->getName(),
            'description' => $this->productSerializer->getDescription($product),
            'link' => $this->productSerializer->getProductUrl($product, $baseUrl),
            'image_link' => $this->productSerializer->getImageUrl($product, $baseUrl),
            'price' => [
                'amount' => number_format($price, 2, '.', ''),
                'currency' => (string) ($store->getCurrentCurrencyCode() ?: 'GBP'),
            ],
            'availability' => $inStock ? 'in_stock' : 'out_of_stock',
            'brand' => $this->extractOptionalAttribute($product, ['brand', 'manufacturer']),
            'gtin' => $this->extractOptionalAttribute($product, ['gtin']),
            'item_group_id' => $this->itemGroupId($product, $storeId, $websiteId),
        ];

        // inventory_quantity is deliberately conditional, not unconditional
        // like availability. A default Magento PDP never shows a bare
        // number — it shows "In Stock"/"Out of Stock" text, and only
        // additionally reveals "Only N left" once qty drops to or below the
        // merchant's own cataloginventory/options/stock_threshold_qty
        // (0 = feature off, the shipped default — see
        // Magento\CatalogInventory\Model\Configuration::getStockThresholdQty()).
        // Publishing the raw quantity unconditionally would hand every
        // caller commercially sensitive stock depth the storefront itself
        // never discloses; mirroring the PDP's own disclosure rule keeps
        // this feed to "already on the public product page", same as every
        // other field here.
        if ($this->isLowStockQtyPublic($qty, $storeId)) {
            $item['inventory_quantity'] = $qty;
        }

        return $item;
    }

    /**
     * Still, and only, getFinalPrice() — see the class docblock. A
     * configurable parent's own price model (Magento\ConfigurableProduct
     * \Model\Product\Type\Configurable\Price::getFinalPrice()) computes via
     * the Pricing framework's PriceInfo/FinalPrice mechanism whenever the
     * product's data doesn't already carry a pre-joined `final_price`
     * value, and that computation reads the child products' own price
     * attributes directly — it does not require a price-index join to
     * produce a real number. Live-verified against all 147 configurables in
     * this task's test catalog: every one returns a non-zero
     * getFinalPrice() this way (see the task report). The one retry below
     * exists purely as a defensive fallback for a configurable whose own
     * computation genuinely comes back 0 (e.g. because of catalog data this
     * task's testing didn't happen to cover, not because the collection
     * lacks an index join) — it reloads the SAME product directly (the same
     * load path Controller/Product/Mini.php already uses for accurate
     * per-product pricing) and calls the exact same, single method again.
     * This is a retry with better data, not the multi-method fallback CHAIN
     * (final → price → minimal → cheapest child) the brief rules out. It
     * touches nothing about the $collection query itself, so it cannot
     * reproduce the INNER JOIN regression an earlier version of this method
     * caused by joining the collection to catalog_product_index_price (see
     * the task report) — a rescued price here can, since fix-round-2,
     * change whether build()'s caller skips the row entirely (see build()'s
     * inline comment), but that decision is made by the caller reading this
     * method's return value, never by this method reaching back into the
     * collection.
     */
    private function finalPrice(ProductInterface $product, int $storeId): float
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $price = (float) $product->getFinalPrice();
        if ($price > 0.0 || $product->getTypeId() !== Configurable::TYPE_CODE) {
            return $price;
        }
        try {
            $reloaded = $this->productRepository->getById((int) $product->getId(), false, $storeId);
        } catch (NoSuchEntityException $e) {
            return $price;
        }
        return (float) $reloaded->getFinalPrice();
    }

    /**
     * A configurable's child simple collapses to its parent's SKU so an
     * agent-facing consumer can group variants — never the child's own SKU,
     * which is not something the brief wants surfaced as a standalone
     * buyable id (see the module-level docblock on gate #2 above). A
     * configurable parent, or a standalone simple with no parent link,
     * returns null.
     *
     * getParentIdsByChild() is a raw, store-unaware lookup against
     * catalog_product_super_link — it says nothing about whether the parent
     * itself is enabled, individually visible, or on this website. Every
     * other field in the feed comes from the already-gated $collection in
     * build(); this is the one field derived from a product outside it, so
     * it re-applies the same three gates by hand before trusting the
     * parent's SKU. Without this, a child that legitimately passed every
     * gate could still publish, as item_group_id, the SKU of a parent that
     * is disabled, not visible individually, or assigned to a different
     * website — a cross-publish in exactly the area the gates exist to
     * prevent, even though the leaked value is a bare SKU rather than full
     * product data.
     */
    private function itemGroupId(ProductInterface $product, int $storeId, int $websiteId): ?string
    {
        $parentIds = $this->configurableType->getParentIdsByChild((int) $product->getId());
        if (empty($parentIds)) {
            return null;
        }
        try {
            $parent = $this->productRepository->getById((int) $parentIds[0], false, $storeId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
        /** @var \Magento\Catalog\Model\Product $parent */
        if (!$parent->isVisibleInCatalog() || !$parent->isVisibleInSiteVisibility()) {
            return null;
        }
        $parentWebsiteIds = array_map('intval', (array) $parent->getWebsiteIds());
        if (!in_array($websiteId, $parentWebsiteIds, true)) {
            return null;
        }
        return (string) $parent->getSku();
    }

    /**
     * Best-effort optional attribute lookup for fields (brand, gtin) that
     * are not guaranteed to exist on every catalog's EAV. Never throws —
     * an attribute code this store never created is not an error, just a
     * field the feed omits. Prefers the human-readable option text (for a
     * dropdown-backed attribute like "manufacturer") and falls back to the
     * raw value (for a plain text field like "gtin").
     *
     * @param string[] $codes Attribute codes to try, in order.
     */
    private function extractOptionalAttribute(ProductInterface $product, array $codes): ?string
    {
        foreach ($codes as $code) {
            $value = null;
            try {
                $text = $product->getAttributeText($code);
                if ($text !== false && $text !== null && $text !== '') {
                    $value = $text;
                }
            } catch (\Throwable $e) {
                // Attribute code doesn't exist in this store's EAV — try the next one.
            }
            if ($value === null) {
                $raw = $product->getData($code);
                if ($raw !== null && $raw !== '' && $raw !== false) {
                    $value = $raw;
                }
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, static fn ($v) => $v !== '' && $v !== false));
            }
            if ($value !== null && (string) $value !== '') {
                return (string) $value;
            }
        }
        return null;
    }

    /**
     * True only when the storefront PDP itself would reveal this quantity —
     * see the call site in serializeProduct() for the reasoning. Reads the
     * config directly (XML_PATH_STOCK_THRESHOLD_QTY isn't part of
     * StockConfigurationInterface, only the concrete
     * Magento\CatalogInventory\Model\Configuration class) rather than add a
     * dependency on that concrete class for one value ScopeConfigInterface,
     * already injected, can read just as well.
     */
    private function isLowStockQtyPublic(int $qty, int $storeId): bool
    {
        $threshold = (float) $this->scopeConfig->getValue(
            'cataloginventory/options/stock_threshold_qty',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $threshold > 0 && $qty <= $threshold;
    }

    private function sellerName(int $storeId): string
    {
        $name = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_STORE_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if ($name !== '') {
            return $name;
        }
        return (string) $this->storeManager->getStore($storeId)->getName();
    }
}
