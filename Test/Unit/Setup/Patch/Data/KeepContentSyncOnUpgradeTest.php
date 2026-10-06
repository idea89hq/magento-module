<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Setup\Patch\Data;

use Idea89\Assistant\Setup\Patch\Data\KeepContentSyncOnUpgrade;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

/** CMS sync defaults on for new installs; an existing install keeps its setting. */
class KeepContentSyncOnUpgradeTest extends TestCase
{
    private function run_(int $idea89Rows, int $cmsRows): array
    {
        $inserted = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchOne')->willReturnOnConsecutiveCalls((string) $idea89Rows, (string) $cmsRows);
        $conn->method('insert')->willReturnCallback(function ($t, array $row) use (&$inserted) { $inserted[] = $row; return 1; });
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($conn);
        $setup->method('getTable')->willReturn('core_config_data');
        (new KeepContentSyncOnUpgrade($setup))->apply();
        return $inserted;
    }

    public function testExistingInstallWithoutTheSettingKeepsItOff(): void
    {
        $this->assertSame([['scope' => 'default', 'scope_id' => 0, 'path' => 'idea89/sync/sync_cms', 'value' => '0']], $this->run_(3, 0));
    }

    public function testNewInstallOrSavedSettingIsLeftAlone(): void
    {
        $this->assertSame([], $this->run_(0, 0));
        $this->assertSame([], $this->run_(4, 1));
    }
}
