<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Controller\Products;

use Idea89\Assistant\Controller\Products\Live;
use Idea89\Assistant\Model\PersonalizationConfig;
use Idea89\Assistant\Model\Sync\ProductSerializer;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

/** The on-demand endpoint's full-product and name-search requests. Invented products. */
class LiveTest extends TestCase
{
    private array $data = [];
    private int $code = 200;
    /** @var array<int, array{0: string, 1: mixed, 2: string}> */
    private array $filters = [];

    private function controller(string $body, ProductRepositoryInterface $repo, string $auth = 'Bearer s3cret'): Live
    {
        $json = $this->createMock(Json::class);
        $json->method('setHeader')->willReturnSelf();
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $c) use ($json) { $this->code = $c; return $json; });
        $json->method('setData')->willReturnCallback(function ($d) use ($json) { $this->data = $d; return $json; });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        $req = $this->createMock(Http::class);
        $req->method('getHeader')->willReturn($auth);
        $req->method('getContent')->willReturn($body);
        $config = $this->createMock(PersonalizationConfig::class);
        $config->method('getSigningSecret')->willReturn('s3cret');
        $serializer = $this->createMock(ProductSerializer::class);
        $serializer->method('serialize')->willReturnCallback(fn ($p) => ['sku' => $p->getSku(), 'attribute_list' => []]);
        $serializer->method('shopperPrice')->willReturnCallback(fn ($p) => $p->getSku() === 'CFG-1' ? 1999.99 : null);
        $stockItem = $this->createMock(\Magento\CatalogInventory\Api\Data\StockItemInterface::class);
        $stockItem->method('getQty')->willReturn(3.0);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stocks = $this->createMock(StockRegistryInterface::class);
        $stocks->method('getStockItem')->willReturn($stockItem);
        $criteria = $this->createMock(SearchCriteriaBuilder::class);
        $criteria->method('addFilter')->willReturnCallback(function ($f, $v, $c = 'eq') use ($criteria) { $this->filters[] = [$f, $v, $c]; return $criteria; });
        $criteria->method('setPageSize')->willReturnSelf();
        $criteria->method('setCurrentPage')->willReturnSelf();
        $criteria->method('addSortOrder')->willReturnSelf();
        $criteria->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $sort = $this->createMock(SortOrderBuilder::class);
        $sort->method('setField')->willReturnSelf();
        $sort->method('setAscendingDirection')->willReturnSelf();
        $sort->method('create')->willReturn($this->createMock(SortOrder::class));
        return new Live($factory, $req, $repo, $stocks, $config, $serializer, $criteria, $sort);
    }

    private function product(string $sku, int $status = 1, int $visibility = 4): Product
    {
        $p = $this->createMock(Product::class);
        $p->method('getSku')->willReturn($sku);
        $p->method('getStatus')->willReturn($status);
        $p->method('getVisibility')->willReturn($visibility);
        return $p;
    }

    public function testFullProductBySkuInTheSyncPayload(): void
    {
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('get')->willReturn($this->product('BENCH-1'));
        $this->controller('{"sku":"BENCH-1","full":true}', $repo)->execute();
        $this->assertSame(['product' => ['sku' => 'BENCH-1', 'attribute_list' => []]], $this->data);
    }

    public function testAHiddenProductIsNotReturned(): void
    {
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('get')->willReturn($this->product('CHILD-1', 1, 1));
        $this->controller('{"sku":"CHILD-1","full":true}', $repo)->execute();
        $this->assertSame(['product' => null], $this->data);
    }

    public function testNameSearchRequiresEveryWordAndCapsTheLimit(): void
    {
        $results = $this->createMock(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$this->product('B-1'), $this->product('B-2')]);
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('getList')->willReturn($results);
        $this->controller('{"search":"slatted bench","limit":50}', $repo)->execute();
        $this->assertSame(['B-1', 'B-2'], array_column($this->data['products'], 'sku'));
        $names = array_values(array_filter($this->filters, fn ($f) => $f[0] === 'name'));
        $this->assertSame([['name', '%slatted%', 'like'], ['name', '%bench%', 'like']], $names);
    }

    public function testUnauthorisedAndMalformedRequests(): void
    {
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $this->controller('{"sku":"X","full":true}', $repo, 'Bearer wrong')->execute();
        $this->assertSame(401, $this->code);
        $this->code = 200;
        $this->controller('not json', $repo)->execute();
        $this->assertSame(400, $this->code);
    }

    public function testLegacySkusPriceIsTheSyncPriceNotTheDisplayAmount(): void
    {
        $product = function (string $sku, float $final) {
            $p = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)->disableOriginalConstructor()
                ->onlyMethods(['getSku', 'getId', 'getFinalPrice', 'getStore', 'getStatus', 'getProductUrl'])->getMock();
            $p->method('getSku')->willReturn($sku);
            $p->method('getId')->willReturn(1);
            $p->method('getFinalPrice')->willReturn($final);
            $p->method('getStore')->willReturn($this->createMock(\Magento\Store\Model\Store::class));
            $p->method('getStatus')->willReturn(1);
            $p->method('getProductUrl')->willReturn('https://shop.test/p');
            return $p;
        };
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(fn ($sku) => $sku === 'CFG-1' ? $product('CFG-1', 2399.988001) : $product('SIMPLE-1', 49.99));
        $this->controller('{"skus":["CFG-1","SIMPLE-1"]}', $repo)->execute();
        $this->assertSame([1999.99, 49.99], array_column($this->data['products'], 'price'));
    }
}
