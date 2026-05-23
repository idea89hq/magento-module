<?php

declare(strict_types=1);

namespace Idea89\Assistant\Cron;

use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;

/**
 * Full push of all cart price rules every 15 minutes.
 * Idempotent upsert on the API side handles duplicates.
 */
class SyncPromos
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly Config            $config,
        private readonly Idea89Client      $client,
        private readonly CollectionFactory $ruleCollectionFactory,
        private readonly LoggerInterface   $logger
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();
        if (!$apiKey || !$apiUrl) {
            return;
        }

        /** @var \Magento\SalesRule\Model\ResourceModel\Rule\Collection $collection */
        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToFilter('coupon_type', \Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC);

        $batch = [];
        $synced = 0;

        foreach ($collection as $rule) {
            $code = trim((string) $rule->getPrimaryCoupon()->getCode());
            if ($code === '') {
                continue;
            }

            $toDate = $rule->getToDate();
            $batch[] = [
                'external_id' => (string) $rule->getId(),
                'code'        => $code,
                'description' => $rule->getName() ?: $code,
                'expires_at'  => $toDate ? date('c', strtotime($toDate . ' 23:59:59')) : null,
                'is_active'   => (bool) $rule->getIsActive(),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                if ($this->client->upsertPromos($batch, $apiKey, $apiUrl)) {
                    $synced += count($batch);
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            if ($this->client->upsertPromos($batch, $apiKey, $apiUrl)) {
                $synced += count($batch);
            }
        }

        $this->logger->info('IDEA89: promo sync complete', ['count' => $synced]);
    }
}
