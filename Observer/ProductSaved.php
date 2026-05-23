<?php

declare(strict_types=1);

namespace Idea89\Assistant\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;

/**
 * Queues a product for incremental sync after save.
 * Does NOT make HTTP calls inline — just writes the product ID to core_config_data
 * as a pending queue entry. The drain cron picks it up within a minute.
 */
class ProductSaved implements ObserverInterface
{
    private const XML_PATH_QUEUE = 'idea89/sync/pending_product_ids';

    public function __construct(
        private readonly Config $config,
        private readonly WriterInterface $configWriter,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();
        $productId = (int) $product->getId();

        if (!$productId) {
            return;
        }

        // Simple CSV queue in core_config_data — handles low-frequency saves fine.
        // If a store saves hundreds of products/minute, switch to a dedicated queue table.
        $existing = (string) $this->scopeConfig->getValue(self::XML_PATH_QUEUE);
        $ids = array_filter(explode(',', $existing));

        if (!in_array((string) $productId, $ids, true)) {
            $ids[] = (string) $productId;
            $this->configWriter->save(self::XML_PATH_QUEUE, implode(',', $ids));
        }

        $this->logger->info('IDEA89: queued product for sync', ['product_id' => $productId]);
    }
}
