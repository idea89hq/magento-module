<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Idea89\Assistant\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * A product's attributes as the IDEA89 schema-2 attribute list.
 *
 * Every attribute a shopper can see on the product page (is_visible_on_front)
 * or use to search or filter (is_searchable, is_filterable,
 * is_filterable_in_search) is sent, with its store-view label, its input
 * type, the display value (option labels for selects, a list for
 * multiselects), the raw stored value, a unit where Magento has one (the
 * store's weight unit for `weight`) and the flags themselves.
 *
 * Platform plumbing (prices, media, URLs, meta tags, design and status
 * fields) is never sent: the API has its own fields for what matters there.
 */
class AttributeExtractor
{
    private const XML_PATH_WEIGHT_UNIT = 'general/locale/weight_unit';

    /** Codes that are platform fields, not facts about the product. */
    public const SKIP_CODES = [
        'sku', 'name', 'description', 'short_description', 'price', 'special_price', 'special_from_date',
        'special_to_date', 'cost', 'tier_price', 'msrp', 'msrp_display_actual_price_type', 'price_view',
        'price_type', 'sku_type', 'weight_type', 'shipment_type', 'links_purchased_separately',
        'links_title', 'links_exist', 'samples_title', 'image', 'small_image', 'thumbnail', 'swatch_image',
        'media_gallery', 'gallery', 'image_label', 'small_image_label', 'thumbnail_label', 'url_key', 'url_path',
        'meta_title', 'meta_keyword', 'meta_description', 'status', 'visibility', 'tax_class_id',
        'quantity_and_stock_status', 'category_ids', 'options_container', 'page_layout', 'custom_layout',
        'custom_layout_update', 'custom_layout_update_file', 'custom_design', 'custom_design_from',
        'custom_design_to', 'gift_message_available', 'news_from_date', 'news_to_date', 'has_options',
        'required_options', 'country_of_manufacture_id', 'old_id', 'created_at', 'updated_at',
    ];

    /** @var array<string, bool> */
    private array $skip;

    /** @var array<int|string, array<string, bool>> merchant exclusions per store id */
    private array $excluded = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Config $config,
    ) {
        $this->skip = array_fill_keys(self::SKIP_CODES, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract(Product $product, ?int $storeId = null): array
    {
        $out = [];
        $excluded = $this->excludedFor($storeId);
        foreach ($product->getAttributes() as $attribute) {
            /** @var Attribute $attribute */
            $code = (string) $attribute->getAttributeCode();
            if ($code === '' || isset($this->skip[$code]) || isset($excluded[$code]) || !$this->isShopperFacing($attribute)) {
                continue;
            }
            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === false || (is_array($raw) && $raw === [])) {
                continue;
            }
            $input = (string) $attribute->getFrontendInput();
            if (in_array($input, ['media_image', 'gallery', 'image', 'weee'], true)) {
                continue;
            }
            $value = $this->displayValue($product, $attribute, $input, $raw);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $item = [
                'code'       => $code,
                'label'      => $this->label($attribute, $storeId),
                'value'      => $value,
                'raw'        => is_scalar($raw) ? $raw : null,
                'type'       => $this->type($input, (string) $attribute->getBackendType()),
                'filterable' => (int) $attribute->getIsFilterable() > 0 || (int) $attribute->getIsFilterableInSearch() > 0,
                'searchable' => (bool) $attribute->getIsSearchable(),
                'visible'    => (bool) $attribute->getIsVisibleOnFront(),
            ];
            if ($code === 'weight') {
                $unit = (string) $this->scopeConfig->getValue(self::XML_PATH_WEIGHT_UNIT, ScopeInterface::SCOPE_STORE, $storeId);
                $item['unit'] = $unit === 'kgs' ? 'kg' : ($unit === 'lbs' ? 'lb' : null);
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * The legacy flat code => display map (schema 1), from the same list.
     *
     * @param array<int, array<string, mixed>> $list
     * @return array<string, string>
     */
    public function flatMap(array $list): array
    {
        $map = [];
        foreach ($list as $a) {
            // Schema 1 only ever carried searchable or filterable attributes.
            if (!$a['searchable'] && !$a['filterable']) {
                continue;
            }
            $map[$a['code']] = is_array($a['value']) ? implode(', ', $a['value']) : (string) $a['value'];
        }
        return $map;
    }

    /**
     * The codes the merchant excluded (IDEA89 > Content Sync), read once per
     * store view for a sync run.
     *
     * @return array<string, bool>
     */
    private function excludedFor(?int $storeId): array
    {
        $key = $storeId ?? 'default';
        if (!isset($this->excluded[$key])) {
            $this->excluded[$key] = array_fill_keys(
                $this->config->getExcludedAttributes(ScopeInterface::SCOPE_STORE, $storeId !== null ? (string) $storeId : null),
                true
            );
        }
        return $this->excluded[$key];
    }

    private function isShopperFacing(Attribute $attribute): bool
    {
        return (bool) $attribute->getIsVisibleOnFront()
            || (bool) $attribute->getIsSearchable()
            || (int) $attribute->getIsFilterable() > 0
            || (int) $attribute->getIsFilterableInSearch() > 0;
    }

    private function label(Attribute $attribute, ?int $storeId): string
    {
        $label = $storeId !== null ? (string) $attribute->getStoreLabel($storeId) : '';
        if ($label === '') {
            $label = (string) $attribute->getDefaultFrontendLabel();
        }
        return $label !== '' ? $label : (string) $attribute->getAttributeCode();
    }

    /**
     * Option labels for selects and multiselects; Yes/No for booleans; the
     * stored text otherwise.
     *
     * @param mixed $raw
     * @return string|array<int, string>|null
     */
    private function displayValue(Product $product, Attribute $attribute, string $input, $raw)
    {
        if ($input === 'boolean') {
            return ((int) $raw) === 1 ? 'Yes' : 'No';
        }
        if ($input === 'select' || $input === 'multiselect' || $attribute->usesSource()) {
            $text = $product->getAttributeText((string) $attribute->getAttributeCode());
            if (is_array($text)) {
                $labels = array_values(array_filter(array_map('strval', $text), static fn (string $v): bool => trim($v) !== ''));
                return $labels === [] ? null : $labels;
            }
            if ($text !== false && $text !== null && (string) $text !== '') {
                return (string) $text;
            }
            // A select whose option was deleted: no label to show.
            return null;
        }
        if (is_array($raw)) {
            return null;
        }
        $text = trim(strip_tags((string) $raw));
        // Decimal attributes are stored with six places ("53.300000").
        if ($attribute->getBackendType() === 'decimal' && is_numeric($text)) {
            $text = rtrim(rtrim(number_format((float) $text, 6, '.', ''), '0'), '.');
        }
        return $text === '' ? null : mb_substr($text, 0, 2000);
    }

    private function type(string $input, string $backend): string
    {
        switch ($input) {
            case 'select':
            case 'swatch_visual':
            case 'swatch_text':
                return 'select';
            case 'multiselect':
                return 'multiselect';
            case 'boolean':
                return 'boolean';
            case 'date':
            case 'datetime':
                return 'date';
            case 'price':
            case 'weight':
                return 'number';
        }
        return in_array($backend, ['decimal', 'int'], true) && $input === 'text' ? 'number' : 'text';
    }
}
