<?php

declare(strict_types=1);

namespace Idea89\Assistant\Model\Client;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Idea89Client
{
    private const TIMEOUT = 15;
    private const BATCH_TIMEOUT = 60;

    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    /**
     * Add the store's domain as a header so the API can validate sync origin.
     */
    private function addDomainHeader(): void
    {
        $baseUrl = (string) $this->scopeConfig->getValue('web/secure/base_url', ScopeInterface::SCOPE_STORE);
        if ($baseUrl) {
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if ($host) {
                $this->curl->addHeader('X-IDEA89-Domain', $host);
            }
        }
    }

    /**
     * POST a batch of serialized products to the catalog upsert endpoint.
     * Returns true on success.
     */
    public function upsertProducts(array $products, string $apiKey, string $apiUrl): bool
    {
        if (empty($products)) {
            return true;
        }

        $url = $apiUrl . '/v1/catalog/upsert';
        $body = json_encode(['products' => $products], JSON_THROW_ON_ERROR);

        $this->curl->setTimeout(self::BATCH_TIMEOUT);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-IDEA89-Key', $apiKey);
        $this->addDomainHeader();
        $this->curl->post($url, $body);

        $status = $this->curl->getStatus();
        if ($status !== 200 && $status !== 201) {
            $this->logger->warning('IDEA89: catalog upsert failed', [
                'status' => $status,
                'response' => substr((string) $this->curl->getBody(), 0, 500),
                'product_count' => count($products),
            ]);
            return false;
        }

        return true;
    }

    /**
     * POST a batch of content items (categories, CMS pages, store info) to the API.
     * Returns true on success.
     */
    public function upsertContent(array $items, string $apiKey, string $apiUrl): bool
    {
        if (empty($items)) {
            return true;
        }

        $url  = $apiUrl . '/v1/catalog/content';
        $body = json_encode(['items' => $items], JSON_THROW_ON_ERROR);

        $this->curl->setTimeout(self::TIMEOUT);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-IDEA89-Key', $apiKey);
        $this->addDomainHeader();
        $this->curl->post($url, $body);

        $status = $this->curl->getStatus();
        if ($status !== 200 && $status !== 201) {
            $this->logger->warning('IDEA89: content upsert failed', [
                'status'     => $status,
                'response'   => substr((string) $this->curl->getBody(), 0, 500),
                'item_count' => count($items),
            ]);
            return false;
        }

        return true;
    }

    /**
     * POST a batch of promo codes to the API (synced from cart price rules).
     * Returns true on success.
     */
    public function upsertPromos(array $promos, string $apiKey, string $apiUrl): bool
    {
        if (empty($promos)) {
            return true;
        }

        $url  = $apiUrl . '/v1/catalog/promos';
        $body = json_encode(['promos' => $promos], JSON_THROW_ON_ERROR);

        $this->curl->setTimeout(self::TIMEOUT);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-IDEA89-Key', $apiKey);
        $this->addDomainHeader();
        $this->curl->post($url, $body);

        $status = $this->curl->getStatus();
        if ($status !== 200 && $status !== 201) {
            $this->logger->warning('IDEA89: promo upsert failed', [
                'status'      => $status,
                'response'    => substr((string) $this->curl->getBody(), 0, 500),
                'promo_count' => count($promos),
            ]);
            return false;
        }

        return true;
    }

    /**
     * POST a batch of stock updates (in_stock + qty only) to the lightweight stock endpoint.
     * Does not touch embeddings or any other product fields.
     * Returns true on success.
     */
    public function upsertStock(array $items, string $apiKey, string $apiUrl): bool
    {
        if (empty($items)) {
            return true;
        }

        $url  = $apiUrl . '/v1/catalog/stock';
        $body = json_encode(['items' => $items], JSON_THROW_ON_ERROR);

        $this->curl->setTimeout(self::BATCH_TIMEOUT);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-IDEA89-Key', $apiKey);
        $this->addDomainHeader();
        $this->curl->post($url, $body);

        $status = $this->curl->getStatus();
        if ($status !== 200 && $status !== 201) {
            $this->logger->warning('IDEA89: stock update failed', [
                'status'     => $status,
                'response'   => substr((string) $this->curl->getBody(), 0, 500),
                'item_count' => count($items),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Ping the health endpoint to verify the API key is valid.
     * Returns ['ok' => true] or ['ok' => false, 'error' => '...'].
     */
    public function testConnection(string $apiKey, string $apiUrl): array
    {
        $url = $apiUrl . '/health';

        $this->curl->setTimeout(self::TIMEOUT);
        $this->curl->addHeader('X-IDEA89-Key', $apiKey);
        $this->curl->get($url);

        $status = $this->curl->getStatus();
        if ($status === 200) {
            return ['ok' => true];
        }

        $this->logger->warning('IDEA89: connection test failed', ['status' => $status]);
        return ['ok' => false, 'error' => __('API returned status %1. Check your API key.', $status)->render()];
    }
}
