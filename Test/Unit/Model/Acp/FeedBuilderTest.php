<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Acp;

use Idea89\Assistant\Model\Acp\FeedBuilder;
use Idea89\Assistant\Model\Sync\ProductSerializer;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exhaustive coverage of task-4.3-brief.md's Step 1 list, plus several cases
 * discovered while verifying this task live against a real catalog (see
 * task-4.3-report.md): an out-of-range page observed returning page 1's
 * rows instead of zero rows (mechanism not isolated — see FeedBuilder's own
 * docblock on that guard for the full account, not asserted as fact here);
 * Magento's own AddStockStatusToCollection frontend plugin, which silently
 * drops out-of-stock products from a Collection::load() unless the
 * 'has_stock_status_filter' flag is pre-set; a configurable parent's price
 * needing a targeted retry rather than a collection-wide index join (which
 * turned out to drop non-indexed products, including out-of-stock ones);
 * and a product whose price still can't be resolved being skipped entirely
 * rather than published at "0.00".
 *
 * The three enable/visibility/website gates are real DB-level filters
 * Magento's collection enforces once FeedBuilder configures it — a unit
 * test cannot run that collection, so what IS asserted here is that
 * FeedBuilder asks the mocked Collection for exactly the right constraints.
 * Step 3 of the task report proves the real exclusion on the wire against a
 * live store, including a reconciliation against a raw SQL count.
 */
class FeedBuilderTest extends TestCase
{
    private const STORE_ID = 1;
    private const WEBSITE_ID = 7;

    /** @var ProductRepositoryInterface&MockObject */
    private $productRepository;

    /** @var CollectionFactory&MockObject */
    private $collectionFactory;

    /** @var Collection&MockObject */
    private $collection;

    /** @var StockRegistryInterface&MockObject */
    private $stockRegistry;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var ProductSerializer&MockObject */
    private $productSerializer;

    /** @var Configurable&MockObject */
    private $configurableType;

    /** @var Store&MockObject */
    private $store;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private FeedBuilder $feedBuilder;

    /** @var array<int, array{0: string, 1: mixed}> Captured addAttributeToFilter() calls. */
    private array $capturedAttributeFilters = [];

    /** @var array<int, array{0: string, 1: mixed}> Captured setFlag() calls. */
    private array $capturedFlags = [];

    private ?int $capturedWebsiteFilter = null;
    private ?int $capturedStoreFilter = null;
    private ?int $capturedCurPage = null;
    private ?int $capturedPageSize = null;

    /** @var array<int, StockItemInterface> Per-product-id stock overrides for a single test. */
    private array $stockItemsById = [];

    /** @var Product[] Mutable, read by getItems()'s callback — see stubResults(). */
    private array $itemsToReturn = [];

    /** Mutable, read by getSize()'s callback — see stubResults(). */
    private int $totalToReturn = 0;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collection        = $this->createMock(Collection::class);
        $this->stockRegistry     = $this->createMock(StockRegistryInterface::class);
        $this->storeManager      = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfig       = $this->createMock(ScopeConfigInterface::class);
        $this->productSerializer = $this->createMock(ProductSerializer::class);
        $this->configurableType  = $this->createMock(Configurable::class);
        $this->store             = $this->createMock(Store::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->capturedAttributeFilters = [];
        $this->capturedFlags = [];
        $this->capturedWebsiteFilter = null;
        $this->capturedStoreFilter = null;
        $this->capturedCurPage = null;
        $this->capturedPageSize = null;

        $this->collection->method('addStoreFilter')->willReturnCallback(
            function (int $storeId) {
                $this->capturedStoreFilter = $storeId;
                return $this->collection;
            }
        );
        $this->collection->method('addAttributeToSelect')->willReturnSelf();
        $this->collection->method('addAttributeToFilter')->willReturnCallback(
            function (string $field, $condition) {
                $this->capturedAttributeFilters[] = [$field, $condition];
                return $this->collection;
            }
        );
        $this->collection->method('addWebsiteFilter')->willReturnCallback(
            function (int $websiteId) {
                $this->capturedWebsiteFilter = $websiteId;
                return $this->collection;
            }
        );
        $this->collection->method('setFlag')->willReturnCallback(
            function (string $flag, $value) {
                $this->capturedFlags[] = [$flag, $value];
                return $this->collection;
            }
        );
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('setCurPage')->willReturnCallback(
            function (int $page) {
                $this->capturedCurPage = $page;
                return $this->collection;
            }
        );
        $this->collection->method('setPageSize')->willReturnCallback(
            function (int $pageSize) {
                $this->capturedPageSize = $pageSize;
                return $this->collection;
            }
        );
        // Single callback each (rather than a second method()->willReturn()
        // call from stubResults() below) — same reasoning as
        // $this->stockRegistry above: PHPUnit does not document match order
        // between two unconstrained stubs on the same method, so a second
        // stub is not guaranteed to override the first.
        $this->itemsToReturn = [];
        $this->totalToReturn = 0;
        $this->collection->method('getSize')->willReturnCallback(fn () => $this->totalToReturn);
        $this->collection->method('getItems')->willReturnCallback(fn () => $this->itemsToReturn);

        $this->store->method('getId')->willReturn(self::STORE_ID);
        $this->store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $this->store->method('getBaseUrl')->willReturn('https://example.test/');
        $this->store->method('getCurrentCurrencyCode')->willReturn('GBP');
        $this->store->method('getName')->willReturn('Default Store View');
        $this->storeManager->method('getStore')->willReturn($this->store);

        $this->scopeConfig->method('getValue')->willReturn('Example Store');

        // Default: "not a tracked stock item" — FeedBuilder's own convention
        // (mirroring ProductSerializer) is to assume in-stock, qty 0, when
        // there is no stock row at all. A single callback (rather than
        // several with()-constrained stubs, whose match order PHPUnit does
        // not document as deterministic) lets individual tests override
        // just one product id via $this->stockItemsById.
        $this->stockItemsById = [];
        $defaultStock = $this->createMock(StockItemInterface::class);
        $defaultStock->method('getProductId')->willReturn(0);
        $this->stockRegistry->method('getStockItem')->willReturnCallback(
            function (int $productId) use ($defaultStock) {
                return $this->stockItemsById[$productId] ?? $defaultStock;
            }
        );

        $this->feedBuilder = new FeedBuilder(
            $this->productRepository,
            $this->collectionFactory,
            $this->stockRegistry,
            $this->storeManager,
            $this->scopeConfig,
            $this->productSerializer,
            $this->configurableType,
            $this->logger
        );
    }

    /**
     * @param Product[] $items
     */
    private function stubResults(array $items, int $total): void
    {
        $this->itemsToReturn = $items;
        $this->totalToReturn = $total;
    }

    /**
     * $visibleInCatalog/$visibleInSite/$websiteIds only matter when this
     * product is used as an item_group_id PARENT lookup result — see
     * FeedBuilder::itemGroupId(), which re-applies the three gates to the
     * parent by hand. Defaults represent a parent that passes all three, so
     * existing tests that don't care about that re-gate don't need to think
     * about it.
     */
    private function makeProduct(
        int $id,
        string $sku,
        float $price = 9.9,
        bool $visibleInCatalog = true,
        bool $visibleInSite = true,
        array $websiteIds = [self::WEBSITE_ID]
    ): Product {
        /** @var Product&MockObject $product */
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn('Product ' . $sku);
        $product->method('getFinalPrice')->willReturn($price);
        $product->method('isVisibleInCatalog')->willReturn($visibleInCatalog);
        $product->method('isVisibleInSiteVisibility')->willReturn($visibleInSite);
        $product->method('getWebsiteIds')->willReturn($websiteIds);
        return $product;
    }

    private function assertAttributeFilterCaptured(string $field, $condition): void
    {
        foreach ($this->capturedAttributeFilters as $captured) {
            if ($captured[0] === $field && $captured[1] === $condition) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail(sprintf(
            'Expected a Collection::addAttributeToFilter("%s", %s) call; got: %s',
            $field,
            var_export($condition, true),
            var_export($this->capturedAttributeFilters, true)
        ));
    }

    public function testDisabledProductsAreExcludedByStatusFilter(): void
    {
        $this->feedBuilder->build(1, 200);
        $this->assertAttributeFilterCaptured('status', Status::STATUS_ENABLED);
    }

    public function testNotVisibleIndividuallyProductsAreExcludedByVisibilityFilter(): void
    {
        $this->feedBuilder->build(1, 200);
        $this->assertAttributeFilterCaptured('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);
    }

    public function testProductsOutsideTheCurrentWebsiteAreExcludedByWebsiteFilter(): void
    {
        $this->feedBuilder->build(1, 200);
        $this->assertSame(self::WEBSITE_ID, $this->capturedWebsiteFilter);
    }

    public function testProductsAreScopedToTheCurrentStore(): void
    {
        $this->feedBuilder->build(1, 200);
        $this->assertSame(self::STORE_ID, $this->capturedStoreFilter);
    }

    /**
     * Discovered live (see task-4.3-report.md): Magento's own
     * AddStockStatusToCollection frontend plugin silently drops every
     * out-of-stock product from Collection::load() unless this flag is set
     * first — a real product in this task's own test catalog vanished from
     * the feed entirely (never reaching serializeProduct(), so the
     * out-of-stock JSON shape was never even in play) until this was added.
     */
    public function testBypassesMagentosAutomaticOutOfStockCollectionFilter(): void
    {
        $this->feedBuilder->build(1, 200);

        $found = false;
        foreach ($this->capturedFlags as [$flag, $value]) {
            if ($flag === 'has_stock_status_filter' && $value === true) {
                $found = true;
                break;
            }
        }
        $this->assertTrue(
            $found,
            'Expected Collection::setFlag("has_stock_status_filter", true) to bypass '
            . 'Magento\CatalogInventory\Model\AddStockStatusToCollection'
        );
    }

    /**
     * Regression guard for fix-round-2 (task report has the full account):
     * fix-round-1 addressed a £0.00 configurable price by joining the
     * collection to catalog_product_index_price via addFinalPrice(), which
     * turned out to silently drop every non-price-indexed product —
     * including every out-of-stock one — from `products`/`total`. That join
     * was reverted; this proves the replacement (a targeted per-item
     * reload, scoped to configurables whose own getFinalPrice() is exactly
     * 0) still gets a real price without touching the collection at all.
     */
    /**
     * The retry rescues the row, not just the number: a configurable whose
     * collection-computed price is 0 but whose direct reload prices
     * non-zero must still appear in `products` — fix-round-2's "skip a
     * zero price" rule is applied AFTER this retry, specifically so this
     * case is not caught by it. assertCount() (not just checking index [0])
     * is the actual "not skipped" proof: a skipped product would leave
     * `products` empty and make products[0] undefined.
     */
    public function testRetriesAConfigurableWithAZeroPriceViaADirectReload(): void
    {
        $configurable = $this->createMock(Product::class);
        $configurable->method('getId')->willReturn(62);
        $configurable->method('getSku')->willReturn('MH01');
        $configurable->method('getName')->willReturn('Product MH01');
        $configurable->method('getFinalPrice')->willReturn(0.0);
        $configurable->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $configurable->method('isVisibleInCatalog')->willReturn(true);
        $configurable->method('isVisibleInSiteVisibility')->willReturn(true);
        $configurable->method('getWebsiteIds')->willReturn([self::WEBSITE_ID]);
        $this->stubResults([$configurable], 1);
        $this->configurableType->method('getParentIdsByChild')->with(62)->willReturn([]);

        $reloaded = $this->makeProduct(62, 'MH01', 52.0);
        $this->productRepository->method('getById')->with(62, false, self::STORE_ID)->willReturn($reloaded);

        $result = $this->feedBuilder->build(1, 200);

        $this->assertCount(1, $result['products']);
        $this->assertSame('52.00', $result['products'][0]['price']['amount']);
        $this->assertSame(1, $result['total']);
    }

    /**
     * A non-configurable never attempts the retry (getById() is never
     * called) — and fix-round-2 means it is skipped outright rather than
     * published at "0.00": `products` is empty and `total` drops by one.
     */
    public function testDoesNotRetryASimpleProductWithAZeroPrice(): void
    {
        $simple = $this->makeProduct(5, 'SIMPLE-ZERO', 0.0);
        $simple->method('getTypeId')->willReturn('simple');
        $this->stubResults([$simple], 1);
        $this->configurableType->method('getParentIdsByChild')->with(5)->willReturn([]);

        $this->productRepository->expects($this->never())->method('getById');

        $result = $this->feedBuilder->build(1, 200);

        $this->assertSame([], $result['products']);
        $this->assertSame(0, $result['total']);
    }

    /**
     * Fix-round-2 ruling: a product with no resolvable price is not usable
     * data for an agent, and publishing it at "0.00" is worse than omitting
     * it (an agent could present it as free, and a checkout session could
     * try to transact at that price). Two products go in, one prices at
     * zero and must be skipped; the other must still appear. `total` drops
     * by exactly the skipped count (2 → 1), and the skip is logged at info
     * with the SKU so a merchant can find out why a product is absent.
     */
    public function testSkipsAProductWhosePriceIsStillZeroAfterTheRetry(): void
    {
        $zeroPriced = $this->makeProduct(70, 'ZERO-PRICE', 0.0);
        $normal = $this->makeProduct(71, 'NORMAL-PRICE', 9.99);
        $this->stubResults([$zeroPriced, $normal], 2);
        $this->configurableType->method('getParentIdsByChild')->willReturn([]);

        $this->logger->expects($this->once())->method('info')->with(
            '[idea89] ACP feed: omitting product with no resolvable price',
            ['sku' => 'ZERO-PRICE']
        );

        $result = $this->feedBuilder->build(1, 200);

        $this->assertCount(1, $result['products']);
        $this->assertSame('NORMAL-PRICE', $result['products'][0]['id']);
        $this->assertSame(1, $result['total']);
    }

    public function testPageSizeClampsAtTwoHundred(): void
    {
        $result = $this->feedBuilder->build(1, 5000);

        $this->assertSame(200, $result['page_size']);
        $this->assertSame(200, $this->capturedPageSize);
    }

    public function testPageSizeNeverDropsBelowOne(): void
    {
        $result = $this->feedBuilder->build(1, 0);

        $this->assertSame(1, $result['page_size']);
        $this->assertSame(1, $this->capturedPageSize);
    }

    public function testPageClampsAtOne(): void
    {
        $result = $this->feedBuilder->build(0, 200);
        $this->assertSame(1, $result['page']);
        $this->assertSame(1, $this->capturedCurPage);

        $result = $this->feedBuilder->build(-5, 200);
        $this->assertSame(1, $result['page']);
        $this->assertSame(1, $this->capturedCurPage);
    }

    public function testOutOfPageRangeReturnsAnEmptyProductsArrayNotAnError(): void
    {
        $result = $this->feedBuilder->build(999999, 200);

        $this->assertSame([], $result['products']);
        $this->assertSame(0, $result['total']);
    }

    /**
     * Observed live (see task-4.3-report.md): an out-of-range page returned
     * page 1's rows instead of zero rows, which — unguarded — would leak
     * page 1's products back out under a huge requested page number. The
     * exact mechanism was not isolated (see FeedBuilder's docblock on this
     * guard for the full account and the likelier explanation), but the
     * guard's outcome is correct regardless of cause. This proves
     * FeedBuilder discards the items itself rather than trusting whatever
     * the collection handed back.
     */
    public function testDiscardsItemsTheCollectionReturnsForAnOutOfRangePage(): void
    {
        // total=3, page_size=3 → last real page is 1. Requesting page 2
        // should be empty even though the (mocked) collection — mirroring
        // the observed real-world clamp-to-page-1 behaviour — hands back
        // page 1's item anyway.
        $product = $this->makeProduct(1, 'SKU-1');
        $this->stubResults([$product], 3);

        $result = $this->feedBuilder->build(2, 3);

        $this->assertSame([], $result['products']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['page']);
    }

    public function testOutOfStockProductAppearsRatherThanBeingDropped(): void
    {
        $product = $this->makeProduct(10, 'SKU-OOS');
        $this->stubResults([$product], 1);
        $this->configurableType->method('getParentIdsByChild')->willReturn([]);

        $stock = $this->createMock(StockItemInterface::class);
        $stock->method('getProductId')->willReturn(10);
        $stock->method('getIsInStock')->willReturn(false);
        $stock->method('getQty')->willReturn(0.0);
        $this->stockItemsById[10] = $stock;

        $result = $this->feedBuilder->build(1, 200);

        $this->assertCount(1, $result['products']);
        $this->assertSame('out_of_stock', $result['products'][0]['availability']);
    }

    public function testConfigurablesChildCollapsesToItemGroupId(): void
    {
        $child = $this->makeProduct(55, 'CHILD-1');
        $this->stubResults([$child], 1);

        $this->configurableType->method('getParentIdsByChild')->with(55)->willReturn([42]);

        // Parent passes all three gates by default (makeProduct()'s
        // defaults) — store-scoped getById() lookup, per itemGroupId()'s
        // re-gate.
        $parent = $this->makeProduct(42, 'PARENT-SKU');
        $this->productRepository->method('getById')->with(42, false, self::STORE_ID)->willReturn($parent);

        $result = $this->feedBuilder->build(1, 200);

        $this->assertSame('PARENT-SKU', $result['products'][0]['item_group_id']);
    }

    public function testStandaloneProductHasNoItemGroupId(): void
    {
        $product = $this->makeProduct(99, 'STANDALONE');
        $this->stubResults([$product], 1);

        $this->configurableType->method('getParentIdsByChild')->with(99)->willReturn([]);

        $result = $this->feedBuilder->build(1, 200);

        $this->assertNull($result['products'][0]['item_group_id']);
    }

    /**
     * itemGroupId() re-applies the three gates to the parent by hand
     * (getParentIdsByChild() is a raw, store-unaware lookup that says
     * nothing about the parent's own status/visibility/website) — this and
     * the next two tests are the regression guard for that. Without it, a
     * child that legitimately passed every gate could still publish, as
     * item_group_id, the SKU of a parent that fails one of them.
     */
    public function testItemGroupIdIsNullWhenTheParentIsDisabled(): void
    {
        $child = $this->makeProduct(55, 'CHILD-1');
        $this->stubResults([$child], 1);

        $this->configurableType->method('getParentIdsByChild')->with(55)->willReturn([42]);
        $parent = $this->makeProduct(42, 'PARENT-SKU', 9.9, visibleInCatalog: false);
        $this->productRepository->method('getById')->with(42, false, self::STORE_ID)->willReturn($parent);

        $result = $this->feedBuilder->build(1, 200);

        $this->assertNull($result['products'][0]['item_group_id']);
    }

    public function testItemGroupIdIsNullWhenTheParentIsNotVisibleIndividually(): void
    {
        $child = $this->makeProduct(55, 'CHILD-1');
        $this->stubResults([$child], 1);

        $this->configurableType->method('getParentIdsByChild')->with(55)->willReturn([42]);
        $parent = $this->makeProduct(42, 'PARENT-SKU', 9.9, visibleInSite: false);
        $this->productRepository->method('getById')->with(42, false, self::STORE_ID)->willReturn($parent);

        $result = $this->feedBuilder->build(1, 200);

        $this->assertNull($result['products'][0]['item_group_id']);
    }

    public function testItemGroupIdIsNullWhenTheParentIsOnADifferentWebsite(): void
    {
        $child = $this->makeProduct(55, 'CHILD-1');
        $this->stubResults([$child], 1);

        $this->configurableType->method('getParentIdsByChild')->with(55)->willReturn([42]);
        $parent = $this->makeProduct(42, 'PARENT-SKU', 9.9, websiteIds: [999]);
        $this->productRepository->method('getById')->with(42, false, self::STORE_ID)->willReturn($parent);

        $result = $this->feedBuilder->build(1, 200);

        $this->assertNull($result['products'][0]['item_group_id']);
    }

    public function testItemGroupIdIsNullWhenTheParentLookupMisses(): void
    {
        $child = $this->makeProduct(55, 'CHILD-1');
        $this->stubResults([$child], 1);

        $this->configurableType->method('getParentIdsByChild')->with(55)->willReturn([42]);
        $this->productRepository->method('getById')->with(42, false, self::STORE_ID)
            ->willThrowException(new NoSuchEntityException(__('missing')));

        $result = $this->feedBuilder->build(1, 200);

        $this->assertNull($result['products'][0]['item_group_id']);
    }

    public function testPriceIsFormattedToTwoDecimalsAsAString(): void
    {
        $product = $this->makeProduct(1, 'SKU-1', 24.9);
        $this->stubResults([$product], 1);

        $this->configurableType->method('getParentIdsByChild')->willReturn([]);

        $result = $this->feedBuilder->build(1, 200);

        $this->assertSame('24.90', $result['products'][0]['price']['amount']);
        $this->assertIsString($result['products'][0]['price']['amount']);
        $this->assertSame('GBP', $result['products'][0]['price']['currency']);
    }

    public function testResponseShapeIncludesVersionPageAndSeller(): void
    {
        $result = $this->feedBuilder->build(2, 50);

        $this->assertSame('2026-04-17', $result['version']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(50, $result['page_size']);
        $this->assertSame('Example Store', $result['seller']['name']);
        $this->assertSame('https://example.test', $result['seller']['url']);
    }
}
