<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Data\Form\FormKey;
use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\Config;

class Widget extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly CheckoutConfig $checkoutConfig,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function shouldRender(): bool
    {
        return $this->config->isEnabled() && $this->config->getApiKey() !== '';
    }

    public function getApiKey(): string
    {
        return $this->config->getApiKey();
    }

    public function getApiUrl(): string
    {
        return $this->config->getApiUrl();
    }

    public function getWidgetUrl(): string
    {
        return $this->config->getWidgetUrl();
    }

    public function getWidgetPosition(): string
    {
        return $this->config->getWidgetPosition();
    }

    public function getBrandColor(): string
    {
        return $this->config->getBrandColor();
    }

    /**
     * The inline bootstrap the storefront runs before the widget script loads.
     *
     * Mirrors the WooCommerce plugin's window.__IDEA89_PLATFORM /
     * window.__IDEA89_WC contract (see
     * woocommerce-plugin/includes/frontend/class-idea89-widget.php) so the
     * widget has one detection idiom across all platforms.
     *
     * Paths, not absolute URLs: the widget resolves them against
     * window.location.origin, the same reason _sameOrigin() exists in
     * api/src/widget/product/index.ts — a store's configured base_url can
     * differ from the host the shopper is actually on.
     *
     * Rendered through $secureRenderer in the template so it carries Magento's
     * CSP nonce.
     */
    public function getClientBootstrapJs(): string
    {
        $payload = json_encode(
            [
                'platform'         => 'magento2',
                'checkoutMode'     => $this->checkoutConfig->getMode(),
                'cartPath'         => $this->checkoutConfig->getCartPath(),
                'checkoutPath'     => $this->checkoutConfig->getCheckoutPath(),
                'miniCheckoutPath' => '/idea89/checkout/mini',
                'formKey'          => (string) $this->formKey->getFormKey(),
                // Same key/type as the WooCommerce plugin publishes
                // (class-idea89-widget.php) — the widget reads one
                // platform-neutral ckCfg.checkoutBar boolean either way.
                'checkoutBar'      => $this->checkoutConfig->isCheckoutBarEnabled(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return "window.__IDEA89_PLATFORM='magento2';window.__IDEA89_CHECKOUT={$payload};";
    }
}
