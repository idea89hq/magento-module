<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Sync\ContentSyncer;
use PHPUnit\Framework\TestCase;

/**
 * store_info carries facts only. The merchant's free-text store context lives
 * in the IDEA89 dashboard; a second copy here used to reach every prompt and
 * could contradict it (found on local d89: homewares here, a bikes-and-books
 * group in the dashboard).
 */
class ContentSyncerStoreInfoTest extends TestCase
{
    public function testBodyNamesTheFactsItHas(): void
    {
        $item = ContentSyncer::storeInfoItem([
            'name' => 'd89', 'currency' => 'GBP', 'email' => 'hello@d89.co.uk', 'url' => 'https://d89.co.uk/',
        ]);
        $this->assertSame('store_info', $item['type']);
        $this->assertSame('store', $item['external_id']);
        $this->assertSame('d89', $item['title']);
        $this->assertSame(
            'Store name: d89. Prices are shown in GBP. Contact email: hello@d89.co.uk. Website: https://d89.co.uk.',
            $item['body']
        );
    }

    public function testMagentoPlaceholderEmailIsNotPresentedAsAContact(): void
    {
        $item = ContentSyncer::storeInfoItem(['name' => 'Shop', 'email' => 'owner@example.com']);
        $this->assertSame('Store name: Shop.', $item['body']);
    }

    public function testNoInventedFillerWhenFactsAreMissing(): void
    {
        $item = ContentSyncer::storeInfoItem([]);
        $this->assertSame('Store', $item['title']);
        $this->assertSame('', $item['body']);
        $this->assertStringNotContainsString('An online store selling products at', $item['body']);
    }
}
