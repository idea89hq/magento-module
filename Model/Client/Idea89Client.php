<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

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
     * Add the store's domain and base path so the API can validate sync origin.
     *
     * A store in a subfolder (https://example.com/shop) is a separate IDEA89
     * account from one at the domain root, so the path is what tells the API
     * whether this key belongs to this site.
     */
    private function addDomainHeader(): void
    {
        $baseUrl = (string) $this->scopeConfig->getValue('web/secure/base_url', ScopeInterface::SCOPE_STORE);
        if (!$baseUrl) {
            return;
        }
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if ($host) {
            $this->curl->addHeader('X-IDEA89-Domain', $host);
        }
        $this->curl->addHeader('X-IDEA89-Site-Path', $this->sitePath($baseUrl));
    }

    /**
     * Normalise a base URL's path to '/' (root) or '/segment'.
     *
     * DO NOT change the root value back to the empty string. Magento's
     * HTTP\Client\Curl builds each header as "$name: $value", and libcurl
     * treats "Name: " (colon then only whitespace) as an instruction to REMOVE
     * that header, so an empty value never reaches the API at all — and the API
     * reads an absent header as "this plugin is too old to report a path" and
     * lets the request through. A root store would then be able to sync into a
     * subfolder store's catalog with the wrong API key, which is the exact
     * mix-up this header exists to stop. '/' survives the wire, and the API's
     * normalizeSitePath('/') returns '' — so it round-trips to the same value a
     * root store is registered with, and still matches.
     */
    private function sitePath(string $baseUrl): string
    {
        $path = parse_url($baseUrl, PHP_URL_PATH);
        if (!is_string($path)) {
            return '/';
        }
        $path = strtolower(rtrim($path, '/'));
        if ($path === '') {
            return '/';
        }
        return substr($path, 0, 1) === '/' ? $path : '/' . $path;
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
     * Write the shared checkout-display setting to the merchant's IDEA89
     * account, which is where it lives. Magento holds only a render cache.
     *
     * POST rather than PATCH because Framework\HTTP\Client\Curl exposes only
     * get() and post(); makeRequest() is protected. The API accepts both on
     * the same handler for exactly this reason.
     *
     * Returns true only on a confirmed 200. The caller REFUSES the admin save
     * when this is false: quietly keeping a local value the dashboard never
     * learned about is the divergence this whole design exists to prevent.
     */
    public function updateCheckoutUi(string $value, string $apiKey, string $apiUrl): bool
    {
        return $this->putPluginSetting(['checkout_ui' => $value], $apiKey, $apiUrl, 'checkout UI');
    }

    /**
     * Write the shared assistant name to the merchant's IDEA89 account.
     *
     * Same transport and the same throw handling as updateCheckoutUi: Curl
     * raises on a refused connection rather than returning a status.
     */
    public function updateAssistantName(string $value, string $apiKey, string $apiUrl): bool
    {
        return $this->putPluginSetting(['assistant_name' => $value], $apiKey, $apiUrl, 'assistant name');
    }

    /**
     * Shared writer for /v1/plugin-settings.
     *
     * @param array<string,string> $payload
     */
    private function putPluginSetting(array $payload, string $apiKey, string $apiUrl, string $label): bool
    {
        $url = $apiUrl . '/v1/plugin-settings';
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('X-IDEA89-Key', $apiKey);
            $this->addDomainHeader();
            $this->curl->post($url, $body);

            $status = $this->curl->getStatus();
            if ($status !== 200) {
                $this->logger->warning('IDEA89: ' . $label . ' update failed', [
                    'status' => $status,
                    'response' => substr((string) $this->curl->getBody(), 0, 500),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('IDEA89: ' . $label . ' update could not reach the API', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
