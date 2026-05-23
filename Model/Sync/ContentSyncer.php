<?php

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;

/**
 * Syncs store info, categories, and CMS pages to the IDEA89 API.
 * Called during the daily cron and the "Sync Now" admin button.
 */
class ContentSyncer
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Idea89Client $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    public function syncAll(): void
    {
        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();

        if (!$apiKey) {
            $this->logger->warning('IDEA89 ContentSyncer: skipped — no API key');
            return;
        }

        $items = [];

        if ($this->config->isSyncStoreInfo()) {
            $items[] = $this->buildStoreInfo();
        }

        if ($this->config->isSyncCategories()) {
            $items = array_merge($items, $this->buildCategories());
        }

        if ($this->config->isSyncCms()) {
            $items = array_merge($items, $this->buildCmsPages());
        }

        if (empty($items)) {
            $this->logger->info('IDEA89 ContentSyncer: all content sync toggles off, nothing to send');
            return;
        }

        $this->logger->info('IDEA89 ContentSyncer: syncing content items', ['count' => count($items)]);

        foreach (array_chunk($items, self::BATCH_SIZE) as $batch) {
            $ok = $this->client->upsertContent($batch, $apiKey, $apiUrl);
            if (!$ok) {
                $this->logger->error('IDEA89 ContentSyncer: batch failed');
            }
        }

        $this->logger->info('IDEA89 ContentSyncer: done');
    }

    private function buildStoreInfo(): array
    {
        $store       = $this->storeManager->getStore();
        $storeName   = $store->getName();
        $context     = $this->config->getStoreContext();
        $assistantName = $this->config->getAssistantName();

        $body = trim(implode(' ', array_filter([
            $context,
            $context ? '' : 'An online store selling products at ' . $storeName . '.',
            'Assistant name: ' . $assistantName . '.',
        ])));

        return [
            'type'        => 'store_info',
            'external_id' => 'store',
            'title'       => $storeName,
            'body'        => $body,
        ];
    }

    private function buildCategories(): array
    {
        $store   = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_path', 'is_active', 'level', 'description'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['gt' => 1])
            ->setStoreId($store->getId());

        $items = [];
        foreach ($collection as $cat) {
            $name    = (string) $cat->getName();
            $urlPath = (string) $cat->getData('url_path');
            $desc    = strip_tags((string) $cat->getData('description'));
            $body    = 'Category: ' . str_replace('/', ' > ', $urlPath);
            if ($desc) {
                $body .= "\n" . substr($desc, 0, 500);
            }

            $items[] = [
                'type'        => 'category',
                'external_id' => 'cat_' . $cat->getId(),
                'title'       => $name,
                'body'        => $body,
                'url'         => $urlPath ? $baseUrl . '/' . $urlPath . '.html' : null,
            ];
        }

        return $items;
    }

    private function buildCmsPages(): array
    {
        $collection = $this->pageCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);

        $items = [];
        foreach ($collection as $page) {
            $content = strip_tags((string) $page->getContent());
            // Skip near-empty pages (nav blocks, cookie notices, etc.)
            if (mb_strlen($content) < 80) {
                continue;
            }

            $items[] = [
                'type'        => 'cms_page',
                'external_id' => 'cms_' . $page->getId(),
                'title'       => (string) $page->getTitle(),
                'body'        => mb_substr($content, 0, 2000),
            ];
        }

        return $items;
    }
}
