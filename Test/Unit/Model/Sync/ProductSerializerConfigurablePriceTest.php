<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Sync\PriceResolver;
use Idea89\Assistant\Model\Sync\ProductSerializer;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * A configurable is priced from its children, as entered (found on a local
 * store, 1.4.0: its own final price had the tax added for display).
 */
class ProductSerializerConfigurablePriceTest extends TestCase
{
    private function child(float $price, bool $salable): Product
    {
        $c = $this->createMock(Product::class);
        $c->method('getFinalPrice')->willReturn($price);
        $c->method('isSalable')->willReturn($salable);
        return $c;
    }

    /** @param Product[] $children */
    private function cheapest(array $children, ?callable $rule = null): ?float
    {
        $type = $this->createMock(Configurable::class);
        $type->method('getUsedProducts')->willReturn($children);
        $parent = $this->createMock(Product::class);
        $parent->method('getTypeInstance')->willReturn($type);
        $prices = $this->createMock(PriceResolver::class);
        $prices->method('shopperPrice')->willReturnCallback($rule ?? fn (?float $p) => $p);

        $s = (new \ReflectionClass(ProductSerializer::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty($s, 'priceResolver');
        $prop->setAccessible(true);
        $prop->setValue($s, $prices);
        $m = new \ReflectionMethod($s, 'cheapestChildPrice');
        $m->setAccessible(true);
        return $m->invoke($s, $parent, $this->createMock(StoreInterface::class));
    }

    public function testTheCheapestChildThatCanBeBought(): void
    {
        $this->assertSame(1999.99, $this->cheapest([$this->child(2499.99, true), $this->child(1999.99, true), $this->child(1499.99, false)]));
    }

    public function testAllChildrenWhenNoneCanBeBought(): void
    {
        $this->assertSame(1499.99, $this->cheapest([$this->child(2499.99, false), $this->child(1499.99, false)]));
    }

    public function testRulePricesApplyPerChildAndNoPricedChildGivesNull(): void
    {
        $this->assertSame(90.0, $this->cheapest([$this->child(100.0, true), $this->child(120.0, true)], fn (?float $p) => $p === 100.0 ? 90.0 : $p));
        $this->assertNull($this->cheapest([$this->child(0.0, true)]));
        $this->assertNull($this->cheapest([]));
    }
}
