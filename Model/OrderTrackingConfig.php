<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed accessor for `idea89/order_tracking/*` config paths.
 *
 * Mirrors LocatorConfig — every getter falls back to a hardcoded default
 * matching etc/config.xml so fresh installs (before any save) get the
 * same behaviour as a save-with-defaults. The widget's cfg JSON does NOT
 * carry these values — they're read at endpoint-time inside the Magento
 * controllers, since order tracking is a same-origin Pattern A feature
 * and never crosses into the IDEA89 API.
 */
class OrderTrackingConfig
{
    public const XML_PATH_ENABLED              = 'idea89/order_tracking/enabled';
    public const XML_PATH_SUPPORT_URL          = 'idea89/order_tracking/support_url';
    public const XML_PATH_SUPPORT_LABEL        = 'idea89/order_tracking/support_label';
    public const XML_PATH_MAX_RECENT_ORDERS    = 'idea89/order_tracking/max_recent_orders';
    public const XML_PATH_SHOW_TRACKING_BUTTON = 'idea89/order_tracking/show_tracking_button';

    private const DEFAULT_SUPPORT_URL    = '/contact';
    private const DEFAULT_SUPPORT_LABEL  = 'Contact support';
    private const DEFAULT_MAX_RECENT     = 3;
    public  const HARD_MAX_RECENT        = 10;
    public  const HARD_MIN_RECENT        = 1;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function isEnabled(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): bool
    {
        // Default Yes when un-configured — fresh installs get order tracking live
        // (matches the universal-feature design decision in the spec).
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENABLED, $scopeType, $scopeCode);
        return $value === null
            ? true
            : $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, $scopeType, $scopeCode);
    }

    public function getSupportUrl(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_SUPPORT_URL, $scopeType, $scopeCode);
        return $value !== '' ? $value : self::DEFAULT_SUPPORT_URL;
    }

    public function getSupportLabel(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_SUPPORT_LABEL, $scopeType, $scopeCode);
        return $value !== '' ? $value : self::DEFAULT_SUPPORT_LABEL;
    }

    /**
     * Clamped to [HARD_MIN_RECENT, HARD_MAX_RECENT]. Anything outside this
     * range is bounded silently so the widget can rely on a safe count.
     */
    public function getMaxRecentOrders(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): int
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_MAX_RECENT_ORDERS, $scopeType, $scopeCode);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_MAX_RECENT;
        return max(self::HARD_MIN_RECENT, min(self::HARD_MAX_RECENT, $value));
    }

    public function isTrackingButtonShown(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SHOW_TRACKING_BUTTON, $scopeType, $scopeCode);
        return $value === null
            ? true
            : $this->scopeConfig->isSetFlag(self::XML_PATH_SHOW_TRACKING_BUTTON, $scopeType, $scopeCode);
    }
}
