<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Customer\Model\Group;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Psr\Log\LoggerInterface;

/**
 * Prices as a logged-out shopper sees them, and their tax basis.
 *
 * getFinalPrice() applies a special price but not a catalogue price rule
 * outside the storefront: the rule price is added by an observer on
 * catalog_product_get_final_price that Magento registers for the frontend
 * and adminhtml areas only, so a cron or CLI sync never sees it. The rule
 * price for the NOT LOGGED IN group on the store's website is read from the
 * catalogue rule index instead, the same table the storefront reads.
 *
 * The tax basis is the store's "Catalog Prices" setting
 * (tax/calculation/price_includes_tax); the rate is the one Magento would
 * apply to the product's tax class at the store's default tax destination.
 */
class PriceResolver
{
    private const XML_PATH_PRICE_INCLUDES_TAX = 'tax/calculation/price_includes_tax';

    /** Customer groups whose tier prices apply to a logged-out shopper. */
    private const ALL_GROUPS_ID = 32000;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CatalogRuleResource $catalogRuleResource,
        private readonly TimezoneInterface $timezone,
        private readonly TaxCalculation $taxCalculation,
        private readonly LoggerInterface $logger,
    ) {}

    /** The catalogue rule price for a logged-out shopper today, or null when no rule applies. */
    public function rulePrice(Product $product, StoreInterface $store): ?float
    {
        try {
            $date = $this->timezone->scopeDate($store->getId());
            $price = $this->catalogRuleResource->getRulePrice(
                $date,
                (int) $store->getWebsiteId(),
                Group::NOT_LOGGED_IN_ID,
                (int) $product->getId()
            );
            return ($price !== false && $price !== null && (float) $price > 0) ? (float) $price : null;
        } catch (\Throwable $e) {
            $this->logger->info('IDEA89: catalogue rule price unavailable', ['product_id' => $product->getId(), 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** The lower of the final price and the rule price, as a logged-out shopper pays it. */
    public function shopperPrice(?float $finalPrice, Product $product, StoreInterface $store): ?float
    {
        $rule = $this->rulePrice($product, $store);
        if ($rule === null) {
            return $finalPrice;
        }
        return $finalPrice === null || $finalPrice <= 0 ? $rule : min($finalPrice, $rule);
    }

    /** True when the store's catalogue prices include tax. */
    public function pricesIncludeTax(StoreInterface $store): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PRICE_INCLUDES_TAX, ScopeInterface::SCOPE_STORE, $store->getId());
    }

    /** The tax rate (percent) for the product's tax class at the store's default destination, or null. */
    public function taxRate(Product $product, StoreInterface $store): ?float
    {
        try {
            $classId = (int) $product->getData('tax_class_id');
            if ($classId <= 0) {
                return 0.0;
            }
            $request = $this->taxCalculation->getRateRequest(null, null, null, $store);
            $request->setData('product_class_id', $classId);
            $rate = (float) $this->taxCalculation->getRate($request);
            return $rate >= 0 && $rate <= 100 ? $rate : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tier prices any shopper can get (all groups or not logged in), as
     * qty / price / customer_group. Percentage tiers are applied to `$base`.
     *
     * @return array<int, array{qty: float, price: float, customer_group: string}>
     */
    public function tierPrices(Product $product, ?float $base): array
    {
        $out = [];
        foreach ((array) $product->getTierPrices() as $tier) {
            $group = (int) $tier->getCustomerGroupId();
            if ($group !== self::ALL_GROUPS_ID && $group !== Group::NOT_LOGGED_IN_ID) {
                continue;
            }
            $qty = (float) $tier->getQty();
            $value = (float) $tier->getValue();
            $ext = $tier->getExtensionAttributes();
            $percent = $ext !== null && method_exists($ext, 'getPercentageValue') ? $ext->getPercentageValue() : null;
            if ($percent !== null && $base !== null && $base > 0) {
                $value = round($base * (1 - ((float) $percent) / 100), 2);
            }
            if ($qty <= 0 || $value <= 0) {
                continue;
            }
            $out[] = [
                'qty'            => $qty,
                'price'          => $value,
                'customer_group' => $group === self::ALL_GROUPS_ID ? 'all' : 'not logged in',
            ];
        }
        return $out;
    }
}
