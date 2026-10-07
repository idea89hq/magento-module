<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\ContentSyncer;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Page\Collection as PageCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * CMS pages are those shoppers can open in the synced store view, and every
 * batch carries their ids so the API can withdraw the rest (found on a local
 * store, 1.4.0: an active page assigned to no store view was answered from).
 */
class ContentSyncerCmsTest extends TestCase
{
    private function page(int $id, string $title): Page
    {
        $p = $this->getMockBuilder(Page::class)->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIdentifier', 'getTitle', 'getContent'])->getMock();
        $p->method('getId')->willReturn($id);
        $p->method('getIdentifier')->willReturn('page-' . $id);
        $p->method('getTitle')->willReturn($title);
        $p->method('getContent')->willReturn('<p>' . str_repeat('A page with enough text to be kept by the sync. ', 3) . '</p>');
        return $p;
    }

    public function testPagesAreFilteredToTheStoreViewAndTheirIdsSent(): void
    {
        $pages = $this->createMock(PageCollection::class);
        $pages->method('addFieldToFilter')->willReturnSelf();
        $pages->expects($this->once())->method('addStoreFilter')->with(1, true)->willReturnSelf();
        $pages->method('getIterator')->willReturn(new \ArrayIterator([$this->page(5, 'Size guide'), $this->page(9, 'Care')]));
        $pageFactory = $this->createMock(PageCollectionFactory::class);
        $pageFactory->method('create')->willReturn($pages);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getDefaultStoreView')->willReturn($store);
        $stores->method('getStore')->willReturn($store);

        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('k');
        $config->method('getApiUrl')->willReturn('https://api.test');
        $config->method('isSyncCms')->willReturn(true);

        $client = $this->createMock(Idea89Client::class);
        $client->expects($this->once())->method('upsertContent')
            ->with(
                $this->callback(fn (array $items) => array_column($items, 'external_id') === ['cms_5', 'cms_9']),
                'k',
                'https://api.test',
                ['cms_5', 'cms_9']
            )
            ->willReturn(true);

        (new ContentSyncer(
            $this->createMock(CategoryCollectionFactory::class),
            $pageFactory,
            $stores,
            $client,
            $config,
            $this->createMock(LoggerInterface::class)
        ))->syncAll();
    }
}
