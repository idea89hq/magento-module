<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Config\Source;

use Idea89\Assistant\Model\Config\Source\ShopperFacingAttributes;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;

/** The exclusion list: what the sync sends, setting-like codes first and marked, nothing pre-selected. */
class ShopperFacingAttributesTest extends TestCase
{
    private function attribute(string $code, string $label, bool $visible, bool $searchable = false): Attribute
    {
        $a = $this->getMockBuilder(Attribute::class)->disableOriginalConstructor()
            ->onlyMethods(['getAttributeCode', 'getDefaultFrontendLabel', 'getIsVisibleOnFront', 'getIsSearchable', 'getIsFilterable', 'getIsFilterableInSearch'])
            ->getMock();
        $a->method('getAttributeCode')->willReturn($code);
        $a->method('getDefaultFrontendLabel')->willReturn($label);
        $a->method('getIsVisibleOnFront')->willReturn($visible);
        $a->method('getIsSearchable')->willReturn($searchable);
        $a->method('getIsFilterable')->willReturn(0);
        $a->method('getIsFilterableInSearch')->willReturn(0);
        return $a;
    }

    public function testListsWhatTheSyncSendsWithSettingLikeCodesFirst(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addVisibleFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->attribute('frame_colour', 'Frame Colour', true),
            $this->attribute('show_size_guide', 'Show Size Guide', true),
            $this->attribute('energy_label', 'Energy Label', true),
            $this->attribute('promo_block_background', 'Promo Block Background', true),
            $this->attribute('internal_note', 'Internal Note', false),   // not sent anyway
            $this->attribute('url_key', 'URL Key', false, true),          // platform field
        ]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $options = (new ShopperFacingAttributes($factory))->toOptionArray();
        $this->assertSame(['promo_block_background', 'show_size_guide', 'energy_label', 'frame_colour'], array_column($options, 'value'));
        $this->assertStringContainsString('looks like a setting', (string) $options[0]['label']);
        $this->assertSame('Frame Colour (frame_colour)', $options[3]['label']);
    }
}
