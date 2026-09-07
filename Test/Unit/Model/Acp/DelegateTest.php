<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Acp;

use Idea89\Assistant\Model\Acp\Delegate;
use Magento\Framework\Module\Manager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DelegateTest extends TestCase
{
    /** @var Manager&MockObject */
    private $moduleManager;

    private Delegate $delegate;

    protected function setUp(): void
    {
        $this->moduleManager = $this->createMock(Manager::class);
        $this->delegate      = new Delegate($this->moduleManager);
    }

    public function testTransactionalModuleDetectedWhenInstalled(): void
    {
        $this->moduleManager->method('isEnabled')
            ->with('Magebit_AgenticCommerce')
            ->willReturn(true);

        $this->assertTrue($this->delegate->isTransactionalModuleInstalled());
        $this->assertSame('Magebit_AgenticCommerce', $this->delegate->getInstalledModuleName());
    }

    public function testTransactionalModuleReportsFalseAndNullWhenAbsent(): void
    {
        $this->moduleManager->method('isEnabled')
            ->with('Magebit_AgenticCommerce')
            ->willReturn(false);

        $this->assertFalse($this->delegate->isTransactionalModuleInstalled());
        $this->assertNull($this->delegate->getInstalledModuleName());
    }
}
