<?php

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;

class TestConnection extends Action
{
    public const ADMIN_RESOURCE = 'Idea89_Assistant::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly Idea89Client $client
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();

        if (!$apiKey) {
            return $result->setData(['ok' => false, 'error' => 'No API key configured. Save the config first.']);
        }

        $response = $this->client->testConnection($apiKey, $apiUrl);
        return $result->setData($response);
    }
}
