<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Controller\Adminhtml\Ajax;

use Idea89\Assistant\Controller\Adminhtml\Ajax\TestCheckoutPanel;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Payment\Model\Config as PaymentConfig;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the check-generating logic directly, without an HTTP round trip
 * or an admin session — see the task report for why: {@see TestCheckoutPanel}
 * is a plain \Magento\Backend\App\Action subclass whose constructor chain
 * (Action -> AbstractAction -> Framework's own AbstractAction) does nothing
 * but assign context getters to properties, so a bare Context mock is safe
 * to construct it with directly, matching the sibling MiniTest.php pattern.
 */
class TestCheckoutPanelTest extends TestCase
{
    /**
     * @param array<string, bool> $enabledModules Module name => enabled.
     * @param string[] $activePaymentCodes Codes Payment\Model\Config reports active.
     */
    private function build(
        array $enabledModules = [],
        array $activePaymentCodes = [],
        bool $guestCheckoutEnabled = true
    ): array {
        $context = $this->createMock(Context::class);

        // Captured rather than asserted-on-the-mock directly, same reasoning
        // as MiniTest's $rawHeaders: setData() is the one call we care about,
        // and we want to inspect the array it was given afterwards.
        $jsonData = null;
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json, &$jsonData) {
            $jsonData = $data;
            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(
            static fn (string $name): bool => $enabledModules[$name] ?? false
        );

        $paymentConfig = $this->createMock(PaymentConfig::class);
        $paymentConfig->method('getActiveMethods')->willReturn(array_fill_keys($activePaymentCodes, true));

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool =>
                $path === 'checkout/options/guest_checkout' ? $guestCheckoutEnabled : false
        );

        $controller = new TestCheckoutPanel($context, $jsonFactory, $moduleManager, $paymentConfig, $scopeConfig);

        return ['controller' => $controller, 'data' => &$jsonData];
    }

    public function testCleanStoreReturnsOkWithNoConflicts(): void
    {
        $b = $this->build(['Magento_Checkout' => true]);
        $b['controller']->execute();
        $this->assertSame('ok', $b['data']['verdict']);
        $this->assertCount(1, $b['data']['messages']);
        $this->assertSame('ok', $b['data']['messages'][0]['level']);
        $this->assertStringContainsString('No known conflicts', $b['data']['messages'][0]['text']);
    }

    public function testHyvaCheckoutBlocksTheVerdict(): void
    {
        $b = $this->build(['Magento_Checkout' => true, 'Hyva_Checkout' => true]);
        $b['controller']->execute();
        $this->assertSame('blocked', $b['data']['verdict']);
        $levels = array_column($b['data']['messages'], 'level');
        $this->assertContains('error', $levels);
    }

    public function testMagentoCheckoutDisabledBlocksTheVerdict(): void
    {
        $b = $this->build(['Magento_Checkout' => false]);
        $b['controller']->execute();
        $this->assertSame('blocked', $b['data']['verdict']);
        $text = implode(' ', array_column($b['data']['messages'], 'text'));
        $this->assertStringContainsString('there is no checkout to render', $text);
    }

    public function testAOneStepCheckoutExtensionWarnsButDoesNotBlock(): void
    {
        $b = $this->build(['Magento_Checkout' => true, 'Amasty_Checkout' => true]);
        $b['controller']->execute();
        $this->assertSame('warn', $b['data']['verdict']);
        $text = implode(' ', array_column($b['data']['messages'], 'text'));
        $this->assertStringContainsString('replaces the checkout layout', $text);
    }

    public function testARedirectPaymentMethodWarns(): void
    {
        $b = $this->build(['Magento_Checkout' => true], ['paypal_express']);
        $b['controller']->execute();
        $this->assertSame('warn', $b['data']['verdict']);
        $text = implode(' ', array_column($b['data']['messages'], 'text'));
        $this->assertStringContainsString('sends shoppers to its own site to pay', $text);
    }

    public function testGuestCheckoutOffWarns(): void
    {
        $b = $this->build(['Magento_Checkout' => true], [], false);
        $b['controller']->execute();
        $this->assertSame('warn', $b['data']['verdict']);
        $text = implode(' ', array_column($b['data']['messages'], 'text'));
        $this->assertStringContainsString('Guest checkout is off', $text);
    }

    public function testRecaptchaEnabledWarns(): void
    {
        // Not in the brief's table — added because Magento_ReCaptchaCheckout
        // is a live, verified-enabled module on the target store and a
        // plausible framed-checkout failure mode the table did not cover.
        $b = $this->build(['Magento_Checkout' => true, 'Magento_ReCaptchaCheckout' => true]);
        $b['controller']->execute();
        $this->assertSame('warn', $b['data']['verdict']);
        $text = implode(' ', array_column($b['data']['messages'], 'text'));
        $this->assertStringContainsString('reCAPTCHA', $text);
    }

    public function testAnErrorOutranksAWarnInTheVerdict(): void
    {
        // Hyvä (error) and guest checkout off (warn) both hit; the verdict
        // must reflect the worst level seen, not the order checks ran in.
        $b = $this->build(['Magento_Checkout' => true, 'Hyva_Checkout' => true], [], false);
        $b['controller']->execute();
        $this->assertSame('blocked', $b['data']['verdict']);
    }

    public function testNoMerchantFacingMessageContainsAnEmDash(): void
    {
        $b = $this->build(
            ['Magento_Checkout' => true, 'Hyva_Checkout' => true, 'Amasty_Checkout' => true, 'Magento_ReCaptchaCheckout' => true],
            ['paypal_express'],
            false
        );
        $b['controller']->execute();
        foreach ($b['data']['messages'] as $message) {
            $this->assertStringNotContainsString("\u{2014}", $message['text']);
        }
    }
}
