<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Idea89\Assistant\Model\Sync\CatalogSyncer;
use Idea89\Assistant\Model\Sync\ContentSyncer;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;
use Psr\Log\LoggerInterface;

class SyncNow extends Action
{
    public const ADMIN_RESOURCE = 'Idea89_Assistant::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CatalogSyncer $catalogSyncer,
        private readonly ContentSyncer $contentSyncer,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Idea89Client $client
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->getApiKey()) {
            return $result->setData(['ok' => false, 'error' => 'No API key configured. Save the config first.']);
        }

        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            set_time_limit(600);

            if ($this->config->isSyncProducts()) {
                $this->catalogSyncer->syncAll();
            }

            // The syncers share this client, so a refusal over the sync key
            // during the product sync is visible here. Content would be
            // refused for the same reason; say why instead of "completed".
            if ($this->client->getSyncKeyRejection() === null) {
                $this->contentSyncer->syncAll();
            }

            $rejection = $this->client->getSyncKeyRejection();
            if ($rejection !== null) {
                return $result->setData(['ok' => false, 'error' => $rejection]);
            }

            return $result->setData(['ok' => true, 'synced' => 'completed']);
        } catch (\Exception $e) {
            $this->logger->error('IDEA89: SyncNow controller failed', ['error' => $e->getMessage()]);
            return $result->setData(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
