<?php

declare(strict_types=1);

namespace Idea89\Assistant\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\CatalogSyncer;

/**
 * Drains the pending product sync queue written by ProductSaved observer.
 * Runs every minute via crontab.xml.
 */
class DrainSyncQueue
{
    private const XML_PATH_QUEUE = 'idea89/sync/pending_product_ids';

    public function __construct(
        private readonly Config $config,
        private readonly CatalogSyncer $syncer,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->getApiKey()) {
            return;
        }

        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_QUEUE);
        $ids = array_filter(array_unique(explode(',', $raw)));

        if (empty($ids)) {
            return;
        }

        // Clear the queue before processing — if sync fails we log it, not re-queue
        // (daily full sync will reconcile any missed products)
        $this->configWriter->save(self::XML_PATH_QUEUE, '');

        $this->logger->info('IDEA89: draining sync queue', ['count' => count($ids)]);

        foreach ($ids as $productId) {
            $this->syncer->syncProduct((int) $productId);
        }
    }
}
