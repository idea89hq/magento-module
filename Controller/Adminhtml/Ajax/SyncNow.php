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
        private readonly LoggerInterface $logger
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

            $this->contentSyncer->syncAll();

            return $result->setData(['ok' => true, 'synced' => 'completed']);
        } catch (\Exception $e) {
            $this->logger->error('IDEA89: SyncNow controller failed', ['error' => $e->getMessage()]);
            return $result->setData(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
