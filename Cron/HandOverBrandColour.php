<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Cron;

use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * One-time handover of the removed Brand Colour field.
 *
 * The field (Widget Appearance > Brand Colour) was sent to the widget as
 * data-color and silently beat the colour picked in the IDEA89 dashboard.
 * The colour now lives only in the dashboard. Each saved value is sent once;
 * IDEA89 uses it only while the dashboard is still on the theme's own
 * palette, so shoppers keep seeing the colour they saw before. The config row
 * is deleted once IDEA89 has confirmed; until then it is retried every hour,
 * so an unreachable API costs nothing but a later try.
 *
 * The default scope goes first, then websites, then store views: for one API
 * key the first value used wins, and the default is what most pages showed.
 */
class HandOverBrandColour
{
    public const PATH = 'idea89/widget/brand_color';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config             $config,
        private readonly Idea89Client       $client,
        private readonly LoggerInterface    $logger
    ) {}

    public function execute(): void
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('core_config_data');
        $rows = $conn->fetchAll(
            $conn->select()
                ->from($table, ['config_id', 'scope', 'scope_id', 'value'])
                ->where('path = ?', self::PATH)
        );
        if (!$rows) {
            return;
        }
        $order = ['default' => 0, 'websites' => 1, 'stores' => 2];
        usort($rows, static fn (array $a, array $b): int =>
            [($order[$a['scope']] ?? 3), (int) $a['scope_id']] <=> [($order[$b['scope']] ?? 3), (int) $b['scope_id']]);

        foreach ($rows as $row) {
            $colour = trim((string) $row['value']);
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
                // Empty or not a colour the widget could use: nothing to hand over.
                $this->delete((int) $row['config_id']);
                continue;
            }
            [$scopeType, $scopeCode] = $this->scope((string) $row['scope'], (int) $row['scope_id']);
            $apiKey = $this->config->getApiKey($scopeType, $scopeCode);
            if ($apiKey === '') {
                continue; // not connected yet; try again once it is
            }
            $apiUrl = $this->config->getApiUrl($scopeType, $scopeCode);
            if (!$this->client->seedBrandColor($colour, $apiKey, $apiUrl)) {
                continue;
            }
            $this->delete((int) $row['config_id']);
            $this->logger->info('IDEA89: brand colour handed over to the dashboard', [
                'scope' => $row['scope'], 'scope_id' => (int) $row['scope_id'],
            ]);
        }
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function scope(string $scope, int $id): array
    {
        return match ($scope) {
            'websites' => [ScopeInterface::SCOPE_WEBSITES, (string) $id],
            'stores'   => [ScopeInterface::SCOPE_STORES, (string) $id],
            default    => [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null],
        };
    }

    private function delete(int $configId): void
    {
        $conn = $this->resource->getConnection();
        $conn->delete($this->resource->getTableName('core_config_data'), ['config_id = ?' => $configId]);
    }
}
