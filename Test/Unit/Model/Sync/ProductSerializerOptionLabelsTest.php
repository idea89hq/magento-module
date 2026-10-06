<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Sync\AttributeExtractor;
use Idea89\Assistant\Model\Sync\PriceResolver;
use Idea89\Assistant\Model\Sync\ProductSerializer;
use Idea89\Assistant\Model\Sync\StockResolver;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\DataObject;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;

/**
 * Each variant says what its options are called, as the product page shows
 * them: an option code need not ("..._chair_option" is the heat and massage
 * option). Found on a local store, 1.4.0.
 */
class ProductSerializerOptionLabelsTest extends TestCase
{
    private function attribute(int $id, string $code, string $storeLabel): Attribute
    {
        $a = $this->getMockBuilder(Attribute::class)->disableOriginalConstructor()
            ->onlyMethods(['getAttributeCode', 'getId', 'getStoreLabel', 'getDefaultFrontendLabel', 'getSource'])->getMock();
        $a->method('getAttributeCode')->willReturn($code);
        $a->method('getId')->willReturn($id);
        $a->method('getStoreLabel')->willReturn($storeLabel);
        $a->method('getDefaultFrontendLabel')->willReturn('Admin ' . $code);
        $a->method('getSource')->willThrowException(new \Exception('no source in a unit test'));
        return $a;
    }

    private function child(string $sku, array $values): Product
    {
        $c = $this->getMockBuilder(Product::class)->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getSku', 'getName', 'getFinalPrice', 'getPrice', 'getAttributeText', 'getData'])->getMock();
        $c->method('getId')->willReturn(crc32($sku) % 1000);
        $c->method('getSku')->willReturn($sku);
        $c->method('getName')->willReturn($sku);
        $c->method('getFinalPrice')->willReturn(100.0);
        $c->method('getPrice')->willReturn(100.0);
        $c->method('getAttributeText')->willReturnCallback(fn ($code) => $values[$code] ?? false);
        $c->method('getData')->willReturnCallback(fn ($code = '') => isset($values[$code]) ? '7' : null);
        return $c;
    }

    public function testVariantsCarryTheOptionLabelsShoppersSee(): void
    {
        // A product-level option label ("Heat and Massage Option") wins; else the attribute's store label.
        $massage = new DataObject(['label' => 'Heat and Massage Option', 'product_attribute' => $this->attribute(1, 'chair_option', 'Chair Option')]);
        $size = new DataObject(['label' => '', 'product_attribute' => $this->attribute(2, 'chair_size', 'Chair Size')]);
        $type = $this->createMock(Configurable::class);
        $type->method('getUsedProducts')->willReturn([
            $this->child('C-S-N', ['chair_option' => 'No', 'chair_size' => 'Small']),
            $this->child('C-S-Y', ['chair_option' => 'Yes', 'chair_size' => 'Small']),
        ]);
        $type->method('getConfigurableAttributes')->willReturn([$massage, $size]);
        $parent = $this->getMockBuilder(Product::class)->disableOriginalConstructor()
            ->onlyMethods(['getTypeId', 'getTypeInstance'])->getMock();
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getTypeInstance')->willReturn($type);

        $s = (new \ReflectionClass(ProductSerializer::class))->newInstanceWithoutConstructor();
        $set = function (string $prop, $value) use ($s): void {
            $p = new \ReflectionProperty($s, $prop);
            $p->setAccessible(true);
            $p->setValue($s, $value);
        };
        $stock = $this->createMock(StockResolver::class);
        $stock->method('stockFor')->willReturn(['in_stock' => true, 'qty' => 2]);
        $prices = $this->createMock(PriceResolver::class);
        $prices->method('shopperPrice')->willReturnArgument(0);
        $attrs = $this->createMock(AttributeExtractor::class);
        $attrs->method('extract')->willReturn([]);
        $set('stockResolver', $stock);
        $set('priceResolver', $prices);
        $set('attributeExtractor', $attrs);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $m = new \ReflectionMethod($s, 'extractVariants');
        $m->setAccessible(true);
        $variants = $m->invoke($s, $parent, [], $store);

        $this->assertCount(2, $variants);
        $this->assertSame(['chair_option' => 'Yes', 'chair_size' => 'Small'], $variants[1]['options']);
        $this->assertSame(['chair_option' => 'Heat and Massage Option', 'chair_size' => 'Chair Size'], $variants[1]['option_labels']);
    }
}
