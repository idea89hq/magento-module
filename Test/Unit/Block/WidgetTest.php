<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Block;

use Idea89\Assistant\Block\Widget;
use Idea89\Assistant\Model\CheckoutConfig;
use Idea89\Assistant\Model\Config;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class WidgetTest extends TestCase
{
    private function makeBlock(
        string $mode,
        string $cartPath,
        string $checkoutPath,
        bool $checkoutBarEnabled = true
    ): Widget {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getApiKey')->willReturn('pk_test_123');

        $checkoutConfig = $this->createMock(CheckoutConfig::class);
        $checkoutConfig->method('getMode')->willReturn($mode);
        $checkoutConfig->method('getCartPath')->willReturn($cartPath);
        $checkoutConfig->method('getCheckoutPath')->willReturn($checkoutPath);
        $checkoutConfig->method('isCheckoutBarEnabled')->willReturn($checkoutBarEnabled);

        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('fk_abc');

        $objectManager = new ObjectManager($this);

        return $objectManager->getObject(Widget::class, [
            'config'         => $config,
            'checkoutConfig' => $checkoutConfig,
            'formKey'        => $formKey,
        ]);
    }

    public function testBootstrapDeclaresTheMagento2Platform(): void
    {
        $js = $this->makeBlock(CheckoutConfig::MODE_OFF, '/checkout/cart/', '/checkout/')
            ->getClientBootstrapJs();
        $this->assertStringContainsString("window.__IDEA89_PLATFORM='magento2'", $js);
    }

    public function testBootstrapCarriesTheConfiguredMode(): void
    {
        $js = $this->makeBlock(CheckoutConfig::MODE_EMBEDDED, '/checkout/cart/', '/checkout/')
            ->getClientBootstrapJs();
        $decoded = json_decode($this->extractPayload($js), true);
        $this->assertSame('magento2', $decoded['platform']);
        $this->assertSame('embedded', $decoded['checkoutMode']);
        $this->assertSame('/checkout/cart/', $decoded['cartPath']);
        $this->assertSame('/checkout/', $decoded['checkoutPath']);
        $this->assertSame('fk_abc', $decoded['formKey']);
    }

    public function testBootstrapCarriesTheCheckoutBarSetting(): void
    {
        // Same key/type ('checkoutBar', boolean) the WooCommerce plugin
        // publishes — the widget reads one platform-neutral field.
        $js = $this->makeBlock(CheckoutConfig::MODE_EXPRESS, '/checkout/cart/', '/checkout/', true)
            ->getClientBootstrapJs();
        $this->assertSame(true, json_decode($this->extractPayload($js), true)['checkoutBar']);

        $js = $this->makeBlock(CheckoutConfig::MODE_EXPRESS, '/checkout/cart/', '/checkout/', false)
            ->getClientBootstrapJs();
        $this->assertSame(false, json_decode($this->extractPayload($js), true)['checkoutBar']);
    }

    public function testBootstrapEscapesClosingScriptTags(): void
    {
        // A merchant could put "</script>" in a path field. Without JSON_HEX_TAG
        // the inline <script> would terminate early and the page would break.
        $js = $this->makeBlock(CheckoutConfig::MODE_EXPRESS, '/</script><b>/', '/checkout/')
            ->getClientBootstrapJs();
        $this->assertStringNotContainsString('</script>', $js);
    }

    private function extractPayload(string $js): string
    {
        preg_match('/window\.__IDEA89_CHECKOUT=(\{.*\});/s', $js, $m);
        return $m[1] ?? '{}';
    }
}
