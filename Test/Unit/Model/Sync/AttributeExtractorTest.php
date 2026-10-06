<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\AttributeExtractor;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * Attributes sent for a product: storefront-visible, searchable or
 * filterable ones, with store labels, option labels and flags. Invented
 * attributes.
 */
class AttributeExtractorTest extends TestCase
{
    private function attribute(string $code, string $input, array $flags, string $label = '', bool $source = false, ?string $backend = null): Attribute
    {
        $a = $this->getMockBuilder(Attribute::class)->disableOriginalConstructor()
            ->onlyMethods(['getAttributeCode', 'getFrontendInput', 'getBackendType', 'getIsVisibleOnFront', 'getIsSearchable',
                'getIsFilterable', 'getIsFilterableInSearch', 'getStoreLabel', 'getDefaultFrontendLabel', 'usesSource'])
            ->getMock();
        $a->method('getAttributeCode')->willReturn($code);
        $a->method('getFrontendInput')->willReturn($input);
        $a->method('getBackendType')->willReturn($backend ?? ($input === 'text' ? 'varchar' : 'int'));
        $a->method('getIsVisibleOnFront')->willReturn($flags['visible'] ?? false);
        $a->method('getIsSearchable')->willReturn($flags['searchable'] ?? false);
        $a->method('getIsFilterable')->willReturn($flags['filterable'] ?? 0);
        $a->method('getIsFilterableInSearch')->willReturn(0);
        $a->method('getStoreLabel')->willReturn($label);
        $a->method('getDefaultFrontendLabel')->willReturn('Admin ' . $code);
        $a->method('usesSource')->willReturn($source);
        return $a;
    }

    public function testSendsShopperFacingAttributesWithLabelsOptionLabelsAndFlags(): void
    {
        $attrs = [
            $this->attribute('material', 'select', ['visible' => true, 'filterable' => 1], 'Material', true),
            $this->attribute('features', 'multiselect', ['visible' => true], 'Features', true),
            $this->attribute('care_notes', 'textarea', ['visible' => true], 'Care'),
            $this->attribute('is_vegan', 'boolean', ['filterable' => 1], 'Vegan'),
            $this->attribute('internal_note', 'text', []),                       // not shopper facing
            $this->attribute('url_key', 'text', ['searchable' => true]),         // platform field
            $this->attribute('weight', 'weight', ['visible' => true], 'Weight'),
        ];
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()
            ->onlyMethods(['getAttributes', 'getData', 'getAttributeText'])->getMock();
        $product->method('getAttributes')->willReturn($attrs);
        $values = ['material' => '57', 'features' => '3,4', 'care_notes' => '<p>Wipe clean</p>', 'is_vegan' => '1',
            'internal_note' => 'x', 'url_key' => 'y', 'weight' => '12.5000'];
        $product->method('getData')->willReturnCallback(fn ($k = '') => $values[$k] ?? null);
        $product->method('getAttributeText')->willReturnCallback(fn ($k) => ['material' => 'Oak', 'features' => ['Waterproof', 'Foldable']][$k] ?? false);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('kgs');
        $list = (new AttributeExtractor($config, $this->createMock(Config::class)))->extract($product, 1);
        $byCode = array_column($list, null, 'code');

        $this->assertSame(['material', 'features', 'care_notes', 'is_vegan', 'weight'], array_column($list, 'code'));
        $this->assertSame(['label' => 'Material', 'value' => 'Oak', 'raw' => '57', 'type' => 'select', 'filterable' => true, 'searchable' => false, 'visible' => true],
            array_intersect_key($byCode['material'], array_flip(['label', 'value', 'raw', 'type', 'filterable', 'searchable', 'visible'])));
        $this->assertSame(['Waterproof', 'Foldable'], $byCode['features']['value']);
        $this->assertSame('multiselect', $byCode['features']['type']);
        $this->assertSame('Wipe clean', $byCode['care_notes']['value']);
        $this->assertSame('Yes', $byCode['is_vegan']['value']);
        $this->assertSame('boolean', $byCode['is_vegan']['type']);
        $this->assertSame('kg', $byCode['weight']['unit']);
        $this->assertSame('number', $byCode['weight']['type']);
    }

    public function testDecimalValuesDropTheStoredTrailingZeros(): void
    {
        $attrs = [
            $this->attribute('seat_depth', 'text', ['visible' => true], 'Seat Depth', false, 'decimal'),
            $this->attribute('load', 'price', ['visible' => true], 'Load', false, 'decimal'),
            $this->attribute('code_text', 'text', ['visible' => true], 'Code'),
        ];
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()
            ->onlyMethods(['getAttributes', 'getData', 'getAttributeText'])->getMock();
        $product->method('getAttributes')->willReturn($attrs);
        $values = ['seat_depth' => '53.300000', 'load' => '120.000000', 'code_text' => '1.500'];
        $product->method('getData')->willReturnCallback(fn ($k = '') => $values[$k] ?? null);
        $byCode = array_column((new AttributeExtractor($this->createMock(ScopeConfigInterface::class), $this->createMock(Config::class)))->extract($product, 1), null, 'code');

        $this->assertSame('53.3', $byCode['seat_depth']['value']);
        $this->assertSame('53.300000', $byCode['seat_depth']['raw']);
        $this->assertSame('120', $byCode['load']['value']);
        // Text stays as typed.
        $this->assertSame('1.500', $byCode['code_text']['value']);
    }

    public function testAttributesTheMerchantExcludedAreNotSent(): void
    {
        $attrs = [
            $this->attribute('material', 'select', ['visible' => true], 'Material', true),
            $this->attribute('hide_trade_price', 'boolean', ['visible' => true], 'Hide Trade Price'),
            $this->attribute('banner_background', 'text', ['searchable' => true], 'Banner Background'),
        ];
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()
            ->onlyMethods(['getAttributes', 'getData', 'getAttributeText'])->getMock();
        $product->method('getAttributes')->willReturn($attrs);
        $values = ['material' => '57', 'hide_trade_price' => '1', 'banner_background' => '#ffffff'];
        $product->method('getData')->willReturnCallback(fn ($k = '') => $values[$k] ?? null);
        $product->method('getAttributeText')->willReturn('Oak');
        $config = $this->createMock(Config::class);
        $config->expects($this->once())->method('getExcludedAttributes')->with('store', '3')
            ->willReturn(['hide_trade_price', 'banner_background']);
        $extractor = new AttributeExtractor($this->createMock(ScopeConfigInterface::class), $config);

        $list = $extractor->extract($product, 3);
        $this->assertSame(['material'], array_column($list, 'code'));
        $this->assertSame([], $extractor->flatMap(array_filter($list, fn ($a) => $a['code'] !== 'material')));
        // Read once per store view in a run.
        $extractor->extract($product, 3);
    }

    public function testFlatMapKeepsTheSchemaOneSelection(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $map = (new AttributeExtractor($config, $this->createMock(Config::class)))->flatMap([
            ['code' => 'material', 'value' => 'Oak', 'searchable' => false, 'filterable' => true],
            ['code' => 'features', 'value' => ['A', 'B'], 'searchable' => true, 'filterable' => false],
            ['code' => 'care', 'value' => 'Wipe', 'searchable' => false, 'filterable' => false],
        ]);
        $this->assertSame(['material' => 'Oak', 'features' => 'A, B'], $map);
    }
}
