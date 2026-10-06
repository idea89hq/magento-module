<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * CMS page sync is on by default from 1.4.0 (etc/config.xml), so a new
 * install sends its policy and help pages to the assistant without a trip
 * to the settings. An existing install must not change silently: where the
 * merchant never saved the setting it was off, so that value is written
 * explicitly before the new default can apply.
 *
 * An install is "existing" when it already holds any IDEA89 setting (the
 * API key, a sync timestamp, anything under idea89/). A fresh install has
 * none when its data patches run.
 */
class KeepContentSyncOnUpgrade implements DataPatchInterface
{
    private const PATH = 'idea89/sync/sync_cms';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {}

    public function apply(): self
    {
        $conn = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');
        $conn->startSetup();
        $existing = (int) $conn->fetchOne(
            $conn->select()->from($table, ['COUNT(*)'])->where('path LIKE ?', 'idea89/%')
        );
        $set = (int) $conn->fetchOne(
            $conn->select()->from($table, ['COUNT(*)'])
                ->where('path = ?', self::PATH)
                ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
                ->where('scope_id = ?', 0)
        );
        if ($existing > 0 && $set === 0) {
            $conn->insert($table, [
                'scope'    => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                'scope_id' => 0,
                'path'     => self::PATH,
                'value'    => '0',
            ]);
        }
        $conn->endSetup();
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
