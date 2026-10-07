<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Sync;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;

/**
 * Syncs store details, categories, and CMS pages to the IDEA89 API.
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

        $cmsPageIds = null;
        if ($this->config->isSyncCms()) {
            $pages = $this->buildCmsPages();
            $cmsPageIds = array_column($pages, 'external_id');
            $items = array_merge($items, $pages);
        }

        if (empty($items)) {
            $this->logger->info('IDEA89 ContentSyncer: all content sync toggles off, nothing to send');
            return;
        }

        $this->logger->info('IDEA89 ContentSyncer: syncing content items', ['count' => count($items)]);

        foreach (array_chunk($items, self::BATCH_SIZE) as $batch) {
            $ok = $this->client->upsertContent($batch, $apiKey, $apiUrl, $cmsPageIds);
            if (!$ok) {
                $this->logger->error('IDEA89 ContentSyncer: batch failed');
                if ($this->client->getSyncKeyRejection() !== null) {
                    break;
                }
            }
        }

        $this->logger->info('IDEA89 ContentSyncer: done');
    }

    /**
     * The store_info item: plain facts about the store (name, currency,
     * contact email, website), never free text.
     *
     * Merchant-written "store context" lives only in the IDEA89 dashboard
     * (AI & Knowledge). This module used to have its own Store Context field
     * as well: both were put in every chat prompt, neither screen showed the
     * other, and they could contradict each other. Sending the same
     * external_id replaces the old row, so a store that had text here stops
     * sending it on the next sync.
     */
    private function buildStoreInfo(): array
    {
        $store = $this->storeManager->getStore();
        return self::storeInfoItem([
            'name'     => (string) $store->getName(),
            'currency' => (string) $store->getCurrentCurrencyCode(),
            'email'    => $this->config->getGeneralContactEmail(),
            'url'      => (string) $store->getBaseUrl(),
        ]);
    }

    /**
     * Pure builder, shared shape with the WooCommerce plugin. Only facts that
     * exist are included: Magento ships owner@example.com / example.com
     * placeholders, and a made-up contact address would be repeated to
     * shoppers as fact.
     *
     * @param array{name?: string, currency?: string, email?: string, url?: string} $facts
     * @return array{type: string, external_id: string, title: string, body: string}
     */
    public static function storeInfoItem(array $facts): array
    {
        $name  = trim($facts['name'] ?? '');
        $email = trim($facts['email'] ?? '');
        $url   = trim($facts['url'] ?? '');
        $placeholder = '/@(example\.(com|org|net)|localhost)$/i';

        $parts = [];
        if ($name !== '') {
            $parts[] = sprintf('Store name: %s.', $name);
        }
        if (trim($facts['currency'] ?? '') !== '') {
            $parts[] = sprintf('Prices are shown in %s.', trim($facts['currency']));
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !preg_match($placeholder, $email)) {
            $parts[] = sprintf('Contact email: %s.', $email);
        }
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $parts[] = sprintf('Website: %s.', rtrim($url, '/'));
        }

        return [
            'type'        => 'store_info',
            'external_id' => 'store',
            'title'       => $name !== '' ? $name : 'Store',
            'body'        => implode(' ', $parts),
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

    /**
     * Magento's stock CMS pages. None of them is merchant knowledge, and the
     * privacy page ships as the placeholder "Please replace this text with
     * you Privacy Policy" — the assistant was quoting all three to shoppers
     * (idea89 2026-09-17). Matched by identifier so a merchant who rewrites
     * the privacy page under a new identifier is synced normally; the API
     * applies its own content check as a second line.
     */
    private const SKIP_IDENTIFIERS = [
        'no-route',
        'enable-cookies',
        'privacy-policy-cookie-restriction-mode',
    ];

    private function buildCmsPages(): array
    {
        $collection = $this->pageCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        // Only pages shoppers can open in the synced store view (or in all
        // views). An active page assigned to no store view is not on the
        // storefront, and its text was answered from as if it were.
        $store = $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
        $collection->addStoreFilter((int) $store->getId(), true);

        $items = [];
        foreach ($collection as $page) {
            if (in_array((string) $page->getIdentifier(), self::SKIP_IDENTIFIERS, true)) {
                continue;
            }
            $content = strip_tags((string) $page->getContent());
            // Skip near-empty pages (nav blocks, cookie notices, etc.) and
            // the untouched privacy placeholder under any identifier.
            if (mb_strlen($content) < 80 || stripos($content, 'replace this text with') !== false) {
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
