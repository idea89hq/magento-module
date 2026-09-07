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
 * Typed wrapper around the idea89/checkout/* config paths.
 *
 * Two independent axes live in this group:
 *
 *   1. The ladder (`mode`) — how far the assistant carries a shopper who is
 *      already in OUR widget. Exactly one rung at a time.
 *   2. ACP (`acp_*`) — whether agents that are NOT our widget can transact
 *      with the store. Orthogonal; a store may run `embedded` and ACP at once.
 *
 * Never read these paths through scopeConfig directly — this class is the
 * single place the path strings and the default values are written down.
 */
class CheckoutConfig
{
    /** Tier 0 — today's behaviour: navigate to the cart page. */
    public const MODE_OFF = 'off';
    /** Tier 1 — cart summary card in chat, then jump straight to checkout. */
    public const MODE_EXPRESS = 'express';
    /** Tier 2 — the merchant's own Magento checkout inside the chat panel. */
    public const MODE_EMBEDDED = 'embedded';
    /** Tier 3 — assistant-rendered checkout; we place the order. Beta. */
    public const MODE_NATIVE = 'native';

    private const MODES = [
        self::MODE_OFF,
        self::MODE_EXPRESS,
        self::MODE_EMBEDDED,
        self::MODE_NATIVE,
    ];

    private const XML_MODE           = 'idea89/checkout/mode';
    private const XML_CART_PATH      = 'idea89/checkout/cart_path';
    private const XML_CHECKOUT_PATH  = 'idea89/checkout/checkout_path';
    private const XML_NATIVE_METHODS = 'idea89/checkout/native_methods';
    private const XML_CHECKOUT_BAR   = 'idea89/checkout/checkout_bar_enabled';
    private const XML_CHECKOUT_UI    = 'idea89/checkout/checkout_ui';
    private const XML_ACP_ENABLED    = 'idea89/checkout/acp_enabled';
    private const XML_ACP_FEED       = 'idea89/checkout/acp_feed_enabled';
    private const XML_ACP_SECRET     = 'idea89/checkout/acp_secret';

    public const DEFAULT_CART_PATH     = '/checkout/cart/';
    public const DEFAULT_CHECKOUT_PATH = '/checkout/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {}

    public function getMode(
        string $scopeType = ScopeInterface::SCOPE_STORE,
        ?string $scopeCode = null
    ): string {
        $mode = (string) $this->scopeConfig->getValue(self::XML_MODE, $scopeType, $scopeCode);
        // A value we don't recognise (hand-edited row, downgraded module)
        // collapses to the shipped default rather than leaving the widget in an
        // undefined branch. Express is the safe landing spot: it adds a basket
        // confirmation card and changes nothing about the merchant's checkout.
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_EXPRESS;
    }

    public function isExpress(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): bool
    {
        return $this->getMode($s, $c) === self::MODE_EXPRESS;
    }

    public function isEmbedded(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): bool
    {
        return $this->getMode($s, $c) === self::MODE_EMBEDDED;
    }

    public function isNative(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): bool
    {
        return $this->getMode($s, $c) === self::MODE_NATIVE;
    }

    /** True on every rung above Off — i.e. the assistant offers a checkout CTA. */
    public function isAtLeastExpress(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): bool
    {
        return $this->getMode($s, $c) !== self::MODE_OFF;
    }

    public function getCartPath(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): string
    {
        return $this->normalisePath(
            (string) $this->scopeConfig->getValue(self::XML_CART_PATH, $s, $c),
            self::DEFAULT_CART_PATH
        );
    }

    public function getCheckoutPath(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): string
    {
        return $this->normalisePath(
            (string) $this->scopeConfig->getValue(self::XML_CHECKOUT_PATH, $s, $c),
            self::DEFAULT_CHECKOUT_PATH
        );
    }

    /**
     * Payment method codes the merchant has explicitly allowed for Tier 3.
     *
     * @return string[]
     */
    public function getNativeMethods(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_NATIVE_METHODS, $s, $c);
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
    }

    /**
     * The pinned checkout bar the widget docks above its composer whenever
     * the live basket has items. Independent of `mode` — even on Off, the
     * bar's click still routes through the widget's own _goCheckout(), which
     * falls back to a plain cart-page navigation. Defaults ON (etc/config.xml)
     * since the merchant asked for this feature and it is inert on an empty
     * basket; new persistent chrome nonetheless, so it stays a real toggle.
     */
    /**
     * How native checkout presents itself: 'full' or 'inline'.
     *
     * This mirrors the value held in the merchant's IDEA89 account. It is a
     * render cache for the admin form, NOT the source of truth, and the
     * widget does not read it: the widget reads cfg.checkoutUi, served fresh
     * by the API on every load, so a change is live immediately rather than
     * waiting on whatever Magento has cached.
     *
     * An unrecognised stored value collapses to 'full', which is the shipped
     * presentation. A typo must not silently change what every shopper sees.
     */
    public function getCheckoutUi(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_CHECKOUT_UI, $s, $c);

        return $value === 'inline' ? 'inline' : 'full';
    }

    public function isCheckoutBarEnabled(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_CHECKOUT_BAR, $s, $c);
    }

    public function isAcpEnabled(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ACP_ENABLED, $s, $c);
    }

    public function isAcpFeedEnabled(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ACP_FEED, $s, $c);
    }

    public function getAcpSecret(string $s = ScopeInterface::SCOPE_STORE, ?string $c = null): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_ACP_SECRET, $s, $c);
        if ($value === '') {
            return '';
        }
        // Same convention as Model/Config::getApiKey(): Magento encrypted
        // values look like "version:keyId:data"; config:set writes plaintext.
        return preg_match('/^\d+:\d+:/', $value) ? $this->encryptor->decrypt($value) : $value;
    }

    /** Guarantees exactly one leading and one trailing slash. */
    private function normalisePath(string $value, string $default): string
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }
        return '/' . trim($value, '/') . '/';
    }
}
