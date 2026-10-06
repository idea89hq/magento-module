<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\CatalogSyncer;
use Idea89\Assistant\Model\Sync\ProductSerializer;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** A product that is gone, disabled or hidden is removed, not synced. */
class CatalogSyncerTest extends TestCase
{
    private function syncer(ProductRepositoryInterface $repo, Idea89Client $client): CatalogSyncer
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('k');
        $config->method('getApiUrl')->willReturn('https://api.test');
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $stores->method('getDefaultStoreView')->willReturn($store);
        $serializer = $this->createMock(ProductSerializer::class);
        $serializer->method('serialize')->willReturn(['external_id' => '5']);
        return new CatalogSyncer($repo, $this->createMock(SearchCriteriaBuilder::class), $serializer, $client, $config,
            $this->createMock(WriterInterface::class), $this->createMock(LoggerInterface::class), $stores, $this->createMock(CollectionFactory::class));
    }

    private function product(int $status, int $visibility): Product
    {
        $p = $this->createMock(Product::class);
        $p->method('getStatus')->willReturn($status);
        $p->method('getVisibility')->willReturn($visibility);
        return $p;
    }

    public function testEnabledVisibleProductIsUpserted(): void
    {
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('getById')->willReturn($this->product(1, Visibility::VISIBILITY_BOTH));
        $client = $this->createMock(Idea89Client::class);
        $client->expects($this->once())->method('upsertProducts')->with([['external_id' => '5']]);
        $client->expects($this->never())->method('deleteProducts');
        $this->syncer($repo, $client)->syncProduct(5);
    }

    public function testDisabledOrHiddenOrMissingProductIsDeleted(): void
    {
        foreach ([[2, Visibility::VISIBILITY_BOTH], [1, Visibility::VISIBILITY_NOT_VISIBLE], null] as $case) {
            $repo = $this->createMock(ProductRepositoryInterface::class);
            if ($case === null) {
                $repo->method('getById')->willThrowException(new NoSuchEntityException());
            } else {
                $repo->method('getById')->willReturn($this->product($case[0], $case[1]));
            }
            $client = $this->createMock(Idea89Client::class);
            $client->expects($this->once())->method('deleteProducts')->with(['5'], 'k', 'https://api.test');
            $client->expects($this->never())->method('upsertProducts');
            $this->syncer($repo, $client)->syncProduct(5);
        }
    }

    public function testSyncProductAsksForARetryOnlyWhenTheSendFailedRetryably(): void
    {
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('getById')->willReturn($this->product(1, Visibility::VISIBILITY_BOTH));

        $down = $this->createMock(Idea89Client::class);
        // Cleared before the send, then the send's failure.
        $down->method('takeRetryableFailure')->willReturnOnConsecutiveCalls(false, true);
        $this->assertFalse($this->syncer($repo, $down)->syncProduct(5));

        $up = $this->createMock(Idea89Client::class);
        $up->method('takeRetryableFailure')->willReturn(false);
        $this->assertTrue($this->syncer($repo, $up)->syncProduct(5));
    }

    public function testAFullSyncThatCannotReachTheApiStopsAndKeepsLastSynced(): void
    {
        $page = array_fill(0, 100, $this->product(1, Visibility::VISIBILITY_BOTH));
        $results = $this->createMock(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn($page);
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('getList')->willReturn($results);
        $criteria = $this->createMock(SearchCriteriaBuilder::class);
        foreach (['addFilter', 'setPageSize', 'setCurrentPage'] as $m) {
            $criteria->method($m)->willReturnSelf();
        }
        $criteria->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $client = $this->createMock(Idea89Client::class);
        // Five full pages would follow; the first failure ends the run.
        $client->expects($this->once())->method('upsertProducts')->willReturn(false);
        $client->method('getConnectionError')->willReturn('Connection refused');
        $client->expects($this->never())->method('deleteProducts');
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('k');
        $config->method('getApiUrl')->willReturn('https://api.test');
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $stores->method('getDefaultStoreView')->willReturn($store);

        $syncer = new CatalogSyncer($repo, $criteria, $this->createMock(ProductSerializer::class), $client, $config,
            $writer, $this->createMock(LoggerInterface::class), $stores, $this->createMock(CollectionFactory::class));

        $this->assertSame(['synced' => 0, 'failed' => 100, 'withdrawn' => 0], $syncer->syncAll());
    }
}
