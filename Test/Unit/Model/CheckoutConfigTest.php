<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model;

use Idea89\Assistant\Model\CheckoutConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckoutConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var EncryptorInterface&MockObject */
    private $encryptor;

    private CheckoutConfig $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor   = $this->createMock(EncryptorInterface::class);
        $this->config      = new CheckoutConfig($this->scopeConfig, $this->encryptor);
    }

    /**
     * @param array<string,mixed> $values
     */
    private function stubValues(array $values): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(static fn ($path) => $values[$path] ?? null);
        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn ($path) => (bool) ($values[$path] ?? false));
    }

    public function testModeDefaultsToExpressWhenUnset(): void
    {
        // Tier 1 is the shipped default. etc/config.xml supplies it in a real
        // install; this fallback covers the window before the first save.
        $this->stubValues([]);
        $this->assertSame(CheckoutConfig::MODE_EXPRESS, $this->config->getMode());
        $this->assertTrue($this->config->isAtLeastExpress());
    }

    public function testUnknownModeCollapsesToTheShippedDefault(): void
    {
        // A hand-edited core_config_data row, or a value left behind by a
        // downgrade, must never leave the widget in an undefined branch.
        $this->stubValues(['idea89/checkout/mode' => 'teleport']);
        $this->assertSame(CheckoutConfig::MODE_EXPRESS, $this->config->getMode());
    }

    public function testOffIsStillReachableWhenExplicitlySet(): void
    {
        // A merchant who wants the pre-1.3.0 behaviour must be able to get it.
        $this->stubValues(['idea89/checkout/mode' => 'off']);
        $this->assertSame(CheckoutConfig::MODE_OFF, $this->config->getMode());
        $this->assertFalse($this->config->isAtLeastExpress());
    }

    public function testEmbeddedIsExclusiveOnTheLadder(): void
    {
        $this->stubValues(['idea89/checkout/mode' => 'embedded']);
        $this->assertTrue($this->config->isEmbedded());
        $this->assertFalse($this->config->isExpress());
        $this->assertFalse($this->config->isNative());
        $this->assertTrue($this->config->isAtLeastExpress());
    }

    public function testPathsAreNormalisedToLeadingAndTrailingSlash(): void
    {
        $this->stubValues([
            'idea89/checkout/cart_path'     => 'checkout/cart',
            'idea89/checkout/checkout_path' => '/checkout',
        ]);
        $this->assertSame('/checkout/cart/', $this->config->getCartPath());
        $this->assertSame('/checkout/', $this->config->getCheckoutPath());
    }

    public function testPathsFallBackToMagentoDefaults(): void
    {
        $this->stubValues([]);
        $this->assertSame('/checkout/cart/', $this->config->getCartPath());
        $this->assertSame('/checkout/', $this->config->getCheckoutPath());
    }

    public function testNativeMethodsSplitOnCommaAndDropBlanks(): void
    {
        $this->stubValues(['idea89/checkout/native_methods' => 'checkmo,,banktransfer, free ']);
        $this->assertSame(['checkmo', 'banktransfer', 'free'], $this->config->getNativeMethods());
    }

    public function testNativeMethodsIsEmptyByDefault(): void
    {
        // The Tier 3 design constraint: a merchant who switches to Native
        // but never ticks a method must get an empty allowlist, not an
        // error and not every method on by default. This is what lets
        // Facade::context() fall back to the express experience silently.
        $this->stubValues([]);
        $this->assertSame([], $this->config->getNativeMethods());
    }

    public function testCheckoutBarIsEnabledWhenTheFlagIsSet(): void
    {
        // The real "off by default" test double: isSetFlag stubs to false
        // when the path is absent (see stubValues above), the same as it
        // would for a fresh Magento install with no core_config_data row —
        // the ON-by-default behaviour lives in etc/config.xml, not here.
        $this->stubValues(['idea89/checkout/checkout_bar_enabled' => 1]);
        $this->assertTrue($this->config->isCheckoutBarEnabled());
    }

    public function testCheckoutBarCanBeExplicitlyDisabled(): void
    {
        $this->stubValues(['idea89/checkout/checkout_bar_enabled' => 0]);
        $this->assertFalse($this->config->isCheckoutBarEnabled());
    }

    public function testCheckoutBarIsIndependentOfTheLadder(): void
    {
        // Even on Off, the bar's click still routes through the widget's
        // own _goCheckout(), which falls back to a plain cart navigation —
        // it is not gated on `mode`.
        $this->stubValues([
            'idea89/checkout/mode' => 'off',
            'idea89/checkout/checkout_bar_enabled' => 1,
        ]);
        $this->assertSame(CheckoutConfig::MODE_OFF, $this->config->getMode());
        $this->assertTrue($this->config->isCheckoutBarEnabled());
    }

    public function testAcpIsIndependentOfTheLadder(): void
    {
        // A store on the "off" rung can still expose ACP to external agents.
        $this->stubValues([
            'idea89/checkout/mode'        => 'off',
            'idea89/checkout/acp_enabled' => 1,
        ]);
        $this->assertSame(CheckoutConfig::MODE_OFF, $this->config->getMode());
        $this->assertTrue($this->config->isAcpEnabled());
    }

    public function testAcpSecretIsDecryptedWhenStoredEncrypted(): void
    {
        $this->stubValues(['idea89/checkout/acp_secret' => '0:3:abc123==']);
        $this->encryptor->expects($this->once())
            ->method('decrypt')
            ->with('0:3:abc123==')
            ->willReturn('sk_live_plain');
        $this->assertSame('sk_live_plain', $this->config->getAcpSecret());
    }

    public function testAcpSecretSetViaCliIsReturnedAsIs(): void
    {
        // `bin/magento config:set` writes plaintext; it has no "v:k:" prefix.
        $this->stubValues(['idea89/checkout/acp_secret' => 'plain_from_cli']);
        $this->encryptor->expects($this->never())->method('decrypt');
        $this->assertSame('plain_from_cli', $this->config->getAcpSecret());
    }
}
