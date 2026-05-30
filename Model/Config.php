<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed wrapper around core_config_data for all IDEA89 config paths.
 * Always read config through here — never call scopeConfig directly from other classes.
 */
class Config
{
    private const XML_PATH_ENABLED        = 'idea89/general/enabled';
    private const XML_PATH_API_KEY        = 'idea89/general/api_key';
    private const XML_PATH_ASSISTANT_NAME = 'idea89/general/assistant_name';
    private const XML_PATH_STORE_CONTEXT  = 'idea89/general/store_context';
    private const XML_PATH_API_URL        = 'idea89/advanced/api_url';
    private const XML_PATH_WIDGET_URL     = 'idea89/advanced/widget_url';
    private const XML_PATH_POSITION       = 'idea89/widget/position';
    private const XML_PATH_COLOR          = 'idea89/widget/brand_color';
    private const XML_PATH_SYNC_PRODUCTS  = 'idea89/sync/sync_products';
    private const XML_PATH_SYNC_CATS      = 'idea89/sync/sync_categories';
    private const XML_PATH_SYNC_CMS       = 'idea89/sync/sync_cms';
    private const XML_PATH_SYNC_STORE     = 'idea89/sync/sync_store_info';

    public const DEFAULT_API_URL = 'https://api.idea89.com';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {}

    public function isEnabled(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, $scopeType, $scopeCode);
    }

    public function getApiKey(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_API_KEY, $scopeType, $scopeCode);
        if (!$value) {
            return '';
        }
        // Magento encrypted values have the format "version:keyId:data" e.g. "0:3:base64..."
        // Plaintext values (set via config:set CLI) are returned as-is
        return preg_match('/^\d+:\d+:/', $value) ? $this->encryptor->decrypt($value) : $value;
    }

    public function getAssistantName(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        $name = (string) $this->scopeConfig->getValue(self::XML_PATH_ASSISTANT_NAME, $scopeType, $scopeCode);
        return $name ?: 'Shopping Assistant';
    }

    public function getStoreContext(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_STORE_CONTEXT, $scopeType, $scopeCode);
    }

    public function getApiUrl(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        $override = (string) $this->scopeConfig->getValue(self::XML_PATH_API_URL, $scopeType, $scopeCode);
        return rtrim($override ?: self::DEFAULT_API_URL, '/');
    }

    /**
     * URL used for the widget script tag (loaded in the shopper's browser).
     * Falls back to getApiUrl() if not set.
     */
    public function getWidgetUrl(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        $override = (string) $this->scopeConfig->getValue(self::XML_PATH_WIDGET_URL, $scopeType, $scopeCode);
        return rtrim($override ?: $this->getApiUrl($scopeType, $scopeCode), '/');
    }

    public function getWidgetPosition(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_POSITION, $scopeType, $scopeCode) ?: 'bottom-right';
    }

    /**
     * Returns the merchant-configured brand colour, or empty string when not set.
     * Empty string signals the widget to fall back to the dashboard setting.
     */
    public function getBrandColor(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_COLOR, $scopeType, $scopeCode);
    }

    public function isSyncProducts(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): bool
    {
        $v = $this->scopeConfig->getValue(self::XML_PATH_SYNC_PRODUCTS, $scopeType, $scopeCode);
        // Default to true when not yet configured
        return $v === null ? true : $this->scopeConfig->isSetFlag(self::XML_PATH_SYNC_PRODUCTS, $scopeType, $scopeCode);
    }

    public function isSyncCategories(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SYNC_CATS, $scopeType, $scopeCode);
    }

    public function isSyncCms(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SYNC_CMS, $scopeType, $scopeCode);
    }

    public function isSyncStoreInfo(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SYNC_STORE, $scopeType, $scopeCode);
    }
}
