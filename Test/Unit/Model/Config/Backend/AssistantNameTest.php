<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Config\Backend;

use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Config\Backend\AssistantName;
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
 * Assistant Name is now the same value the dashboard edits.
 *
 * Before this, the field fed only the store-context record the module syncs,
 * which the chat prompt injects on every turn, while the name above the
 * conversation came from the IDEA89 account. So the field's own note promised
 * "Name shown in the chat widget header" and the header showed something else,
 * and the assistant could introduce itself by a name the merchant had never
 * seen. Found by reading the rendered admin screen.
 */
class AssistantNameTest extends TestCase
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

    private function makeModel(string $stored = ''): AssistantName
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        $model = new AssistantName(
            $context,
            $this->createMock(Registry::class),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            $this->client,
            $this->config,
            $this->remoteCfg
        );
        $model->setPath('idea89/general/assistant_name');

        return $model;
    }

    public function testAFailedPushRefusesTheSave(): void
    {
        $this->config->method('getApiKey')->willReturn('idea89_live_key');
        $this->config->method('getApiUrl')->willReturn('https://api.idea89.com');
        $this->client->method('updateAssistantName')->willReturn(false);

        $model = $this->makeModel('Mira');
        $model->setValue('Robin');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/nothing was changed/');
        $model->beforeSave();
    }

    public function testASuccessfulPushAllowsTheSave(): void
    {
        $this->config->method('getApiKey')->willReturn('idea89_live_key');
        $this->config->method('getApiUrl')->willReturn('https://api.idea89.com/');
        $this->client->expects($this->once())
            ->method('updateAssistantName')
            ->with('Robin', 'idea89_live_key', 'https://api.idea89.com')
            ->willReturn(true);

        $model = $this->makeModel('Mira');
        $model->setValue('Robin');

        $this->assertSame($model, $model->beforeSave());
    }

    public function testAnUnchangedNameDoesNotCallTheApi(): void
    {
        $this->client->expects($this->never())->method('updateAssistantName');

        $model = $this->makeModel('Mira');
        $model->setValue('Mira');

        $this->assertSame($model, $model->beforeSave());
    }

    public function testTheNameIsTrimmedBeforeItIsCompared(): void
    {
        // Otherwise a stray space counts as a change, fires a pointless push,
        // and stores a name that renders differently from the dashboard's.
        $this->client->expects($this->never())->method('updateAssistantName');

        $model = $this->makeModel('Mira');
        $model->setValue('  Mira  ');

        $model->beforeSave();
        $this->assertSame('Mira', $model->getValue());
    }

    public function testAnEmptyNameIsRefused(): void
    {
        // Saving blank would leave the header with nothing above the
        // conversation, and the model with no name to answer with.
        $this->client->expects($this->never())->method('updateAssistantName');

        $model = $this->makeModel('Mira');
        $model->setValue('   ');

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testANameLongerThanTheDashboardAllowsIsRefusedHereToo(): void
    {
        // The dashboard validates 1..100. Storing 101 here would produce a
        // name the dashboard then refuses to save while already displaying it.
        $this->client->expects($this->never())->method('updateAssistantName');

        $model = $this->makeModel('Mira');
        $model->setValue(str_repeat('x', 101));

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testTheFormShowsTheNameTheAccountHolds(): void
    {
        // The half that makes a dashboard change visible in Magento.
        $this->remoteCfg->method('get')->willReturn(['assistantName' => 'Mira']);

        $model = $this->makeModel('d89 helper');
        $model->setValue('d89 helper');
        $model->afterLoad();

        $this->assertSame('Mira', $model->getValue());
    }

    public function testAnUnreachableApiLeavesTheCachedNameAlone(): void
    {
        // RemoteCfg returns '' when it could not ask. Blanking the field on a
        // network blip would be worse than showing the last known name.
        $this->remoteCfg->method('get')->willReturn(['assistantName' => '']);

        $model = $this->makeModel('d89 helper');
        $model->setValue('d89 helper');
        $model->afterLoad();

        $this->assertSame('d89 helper', $model->getValue());
    }
}
