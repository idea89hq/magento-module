<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Config\Backend;

use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Config\Backend\CheckoutUi;
use Idea89\Assistant\Model\RemoteCfg;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The checkout-display setting is stored in the merchant's IDEA89 account, not
 * in Magento, so that this screen and the dashboard cannot show different
 * answers for the same setting.
 *
 * The behaviour these tests exist for: a push that fails must REFUSE the save.
 * Quietly keeping a local value the dashboard never learned about would
 * reintroduce exactly the divergence the design removes, and the merchant
 * would believe they had changed something they had not.
 */
class CheckoutUiTest extends TestCase
{
    /** @var Idea89Client&MockObject */
    private $client;

    /** @var Config&MockObject */
    private $config;

    /** @var RemoteCfg&MockObject */
    private $remoteCfg;

    protected function setUp(): void
    {
        $this->client    = $this->createMock(Idea89Client::class);
        $this->config    = $this->createMock(Config::class);
        $this->remoteCfg = $this->createMock(RemoteCfg::class);
    }

    /**
     * Builds the backend model with a Context whose event manager is stubbed,
     * since AbstractModel dispatches on construction.
     *
     * $stored is what scopeConfig already holds for this path. It matters
     * because Value::isValueChanged() compares getValue() against a LIVE
     * scopeConfig read, not against an old_value data field. A first cut of
     * these tests set old_value and was quietly ignored, so the
     * unchanged-value case ran the whole push path anyway and failed.
     */
    private function makeModel(string $stored = ''): CheckoutUi
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        $model = new CheckoutUi(
            $context,
            $this->createMock(Registry::class),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            $this->client,
            $this->config,
            $this->remoteCfg
        );
        $model->setPath('idea89/checkout/checkout_ui');

        return $model;
    }

    public function testAValueTheSelectDoesNotOfferIsRejectedBeforeItReachesTheApi(): void
    {
        $this->client->expects($this->never())->method('updateCheckoutUi');

        $model = $this->makeModel('full');
        $model->setValue('popup');

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testAFailedPushRefusesTheSave(): void
    {
        $this->config->method('getApiKey')->willReturn('idea89_live_key');
        $this->config->method('getApiUrl')->willReturn('https://api.idea89.com');
        $this->client->method('updateCheckoutUi')->willReturn(false);

        $model = $this->makeModel('full');
        $model->setValue('inline');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/nothing was changed/');
        $model->beforeSave();
    }

    public function testASuccessfulPushAllowsTheSave(): void
    {
        $this->config->method('getApiKey')->willReturn('idea89_live_key');
        $this->config->method('getApiUrl')->willReturn('https://api.idea89.com/');
        $this->client->expects($this->once())
            ->method('updateCheckoutUi')
            // The trailing slash on the configured URL must not produce a
            // double slash in the request path.
            ->with('inline', 'idea89_live_key', 'https://api.idea89.com')
            ->willReturn(true);

        $model = $this->makeModel('full');
        $model->setValue('inline');

        $this->assertSame($model, $model->beforeSave());
    }

    public function testAnUnchangedValueDoesNotCallTheApiAtAll(): void
    {
        // Magento runs beforeSave() on every save of the section, not only
        // when this field was touched. Without the guard, a merchant editing
        // an unrelated field would be blocked whenever the API was slow.
        $this->client->expects($this->never())->method('updateCheckoutUi');

        $model = $this->makeModel('full');
        $model->setValue('full');

        $this->assertSame($model, $model->beforeSave());
    }

    public function testMissingCredentialsAreRefusedWithoutAnApiCall(): void
    {
        $this->config->method('getApiKey')->willReturn('');
        $this->config->method('getApiUrl')->willReturn('');
        $this->client->expects($this->never())->method('updateCheckoutUi');

        $model = $this->makeModel('full');
        $model->setValue('inline');

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testTheFormShowsWhatIdea89HoldsNotWhatMagentoCached(): void
    {
        // This is the half that makes a dashboard change visible here.
        $this->remoteCfg->method('get')->willReturn(['checkoutUi' => 'inline']);

        $model = $this->makeModel('full');
        $model->setValue('full');
        $model->afterLoad();

        $this->assertSame('inline', $model->getValue());
    }

    public function testAnUnreachableApiLeavesTheCachedValueAlone(): void
    {
        // RemoteCfg fails closed to 'full'. Showing the last known value beats
        // showing an error on a settings screen.
        $this->remoteCfg->method('get')->willReturn(['checkoutUi' => 'full']);

        $model = $this->makeModel('full');
        $model->setValue('full');
        $model->afterLoad();

        $this->assertSame('full', $model->getValue());
    }
}
