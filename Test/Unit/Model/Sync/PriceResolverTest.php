<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Sync\PriceResolver;
use Magento\Catalog\Api\Data\ProductTierPriceInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Model\ResourceModel\Rule as RuleResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Magento\Tax\Model\Calculation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Logged-out shopper prices, tax basis and tier prices. Invented product. */
class PriceResolverTest extends TestCase
{
    private function resolver(?float $rulePrice, bool $inclTax = true): PriceResolver
    {
        $rule = $this->createMock(RuleResource::class);
        $rule->method('getRulePrice')->willReturn($rulePrice === null ? false : $rulePrice);
        $tz = $this->createMock(TimezoneInterface::class);
        $tz->method('scopeDate')->willReturn(new \DateTime('2026-10-06'));
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn($inclTax);
        return new PriceResolver($config, $rule, $tz, $this->createMock(Calculation::class), $this->createMock(LoggerInterface::class));
    }

    private function store(): Store
    {
        $s = $this->createMock(Store::class);
        $s->method('getId')->willReturn(1);
        $s->method('getWebsiteId')->willReturn(1);
        return $s;
    }

    private function tier(int $group, float $qty, float $value): ProductTierPriceInterface
    {
        $t = $this->createMock(ProductTierPriceInterface::class);
        $t->method('getCustomerGroupId')->willReturn($group);
        $t->method('getQty')->willReturn($qty);
        $t->method('getValue')->willReturn($value);
        $t->method('getExtensionAttributes')->willReturn(null);
        return $t;
    }

    public function testACatalogueRuleLowersTheSyncedPrice(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(7);
        $this->assertSame(80.0, $this->resolver(80.0)->shopperPrice(100.0, $product, $this->store()));
        $this->assertSame(100.0, $this->resolver(null)->shopperPrice(100.0, $product, $this->store()));
        // A special price below the rule price stays.
        $this->assertSame(70.0, $this->resolver(80.0)->shopperPrice(70.0, $product, $this->store()));
    }

    public function testTaxBasisFromTheStoreSetting(): void
    {
        $this->assertTrue($this->resolver(null, true)->pricesIncludeTax($this->store()));
        $this->assertFalse($this->resolver(null, false)->pricesIncludeTax($this->store()));
    }

    public function testOnlyTiersEveryShopperGets(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getTierPrices')->willReturn([
            $this->tier(32000, 5, 9.0), $this->tier(0, 10, 8.5), $this->tier(2, 10, 7.0),
        ]);
        $this->assertSame([
            ['qty' => 5.0, 'price' => 9.0, 'customer_group' => 'all'],
            ['qty' => 10.0, 'price' => 8.5, 'customer_group' => 'not logged in'],
        ], $this->resolver(null)->tierPrices($product, 10.0));
    }
}
