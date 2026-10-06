<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Source;

use Idea89\Assistant\Model\Sync\AttributeExtractor;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The product attributes the sync sends (visible on the storefront, searchable
 * or filterable), for the merchant to exclude some. Ones whose code reads like
 * a display or admin setting come first, marked, but none is pre-selected.
 */
class ShopperFacingAttributes implements OptionSourceInterface
{
    /** Code shapes of display and admin switches rather than product facts. */
    private const SETTING_LIKE = '/^(enable|disable|show|hide|use|allow)_|(_toggle|_position|_postion|_override|_panel)$'
        . '|internal_search|marketing|google_|_feed|_block_|background/';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
    ) {}

    public function toOptionArray(): array
    {
        $skip = array_fill_keys(AttributeExtractor::SKIP_CODES, true);
        $settings = [];
        $facts = [];
        $collection = $this->collectionFactory->create()->addVisibleFilter();
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if ($code === '' || isset($skip[$code]) || !$this->isShopperFacing($attribute)) {
                continue;
            }
            $label = trim((string) $attribute->getDefaultFrontendLabel()) ?: $code;
            if (preg_match(self::SETTING_LIKE, $code) === 1) {
                $settings[] = ['value' => $code, 'label' => __('%1 (%2), looks like a setting', $label, $code)];
            } else {
                $facts[] = ['value' => $code, 'label' => sprintf('%s (%s)', $label, $code)];
            }
        }
        $byLabel = static fn (array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']);
        usort($settings, $byLabel);
        usort($facts, $byLabel);
        return array_merge($settings, $facts);
    }

    /** @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute */
    private function isShopperFacing($attribute): bool
    {
        return (bool) $attribute->getIsVisibleOnFront()
            || (bool) $attribute->getIsSearchable()
            || (int) $attribute->getIsFilterable() > 0
            || (int) $attribute->getIsFilterableInSearch() > 0;
    }
}
