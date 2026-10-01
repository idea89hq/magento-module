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

    /** API error codes that mean "the catalogue sync key is the problem". */
    private const SYNC_KEY_ERRORS = ['sync_key_not_set', 'sync_key_required', 'invalid_sync_key'];

    /**
     * Set when a catalogue write was refused because of the sync key, holding
     * the API's merchant-facing message. The write methods still just return
     * false (observers call them during admin saves and must never throw);
     * full syncs check this to stop early, and Sync Now shows it.
     */
    private ?string $syncKeyRejection = null;

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
     * The API's message from the last catalogue write refused over the sync
     * key, or null when none has been refused by this client.
     */
    public function getSyncKeyRejection(): ?string
    {
        return $this->syncKeyRejection;
    }

    /**
     * Remember a 401 whose error code is about the sync key. Any other failure
     * (network, 5xx, validation) leaves the flag alone.
     */
    private function noteSyncKeyRejection(int $status): void
    {
        $message = $this->syncKeyErrorMessage($status, (string) $this->curl->getBody());
        if ($message !== null) {
            $this->syncKeyRejection = $message;
        }
    }

    private function syncKeyErrorMessage(int $status, string $body): ?string
    {
        if ($status !== 401) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !in_array($data['error'] ?? null, self::SYNC_KEY_ERRORS, true)) {
            return null;
        }
        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        return $message !== '' ? $message : (string) __(
            'Catalogue sync was refused (%1). Check the Catalogue sync key field.',
            $data['error']
        );
    }

    /**
     * Add X-IDEA89-Sync-Key for catalog writes when the merchant has set one.
     * Nothing is added when it is unset, so behaviour is unchanged (and an
     * empty value would be dropped by libcurl anyway, see sitePath()).
     */
    private function addSyncKeyHeader(): void
    {
        $syncKey = $this->config->getSyncKey();
        if ($syncKey !== '') {
            $this->curl->addHeader('X-IDEA89-Sync-Key', $syncKey);
        }
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
        $this->addSyncKeyHeader();
        $this->curl->post($url, $body);

        $status = $this->curl->getStatus();
        if ($status !== 200 && $status !== 201) {
            $this->logger->warning('IDEA89: catalog upsert failed', [
                'status' => $status,
                'response' => substr((string) $this->curl->getBody(), 0, 500),
                'product_count' => count($products),
            ]);
            $this->noteSyncKeyRejection($status);
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
        $this->addSyncKeyHeader();
        $this->curl->post($url, $body);

        $status = $this->curl->getStatus();
        if ($status !== 200 && $status !== 201) {
            $this->logger->warning('IDEA89: content upsert failed', [
                'status'     => $status,
                'response'   => substr((string) $this->curl->getBody(), 0, 500),
                'item_count' => count($items),
            ]);
            $this->noteSyncKeyRejection($status);
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
        $this->addSyncKeyHeader();
        $this->curl->post($url, $body);

        $status = $this->curl->getStatus();
        if ($status !== 200 && $status !== 201) {
            $this->logger->warning('IDEA89: promo upsert failed', [
                'status'      => $status,
                'response'    => substr((string) $this->curl->getBody(), 0, 500),
                'promo_count' => count($promos),
            ]);
            $this->noteSyncKeyRejection($status);
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
        $this->addSyncKeyHeader();
        $this->curl->post($url, $body);

        $status = $this->curl->getStatus();
        if ($status !== 200 && $status !== 201) {
            $this->logger->warning('IDEA89: stock update failed', [
                'status'     => $status,
                'response'   => substr((string) $this->curl->getBody(), 0, 500),
                'item_count' => count($items),
            ]);
            $this->noteSyncKeyRejection($status);
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
        if ($status !== 200) {
            $this->logger->warning('IDEA89: connection test failed', ['status' => $status]);
            return ['ok' => false, 'error' => __('API returned status %1. Check your API key.', $status)->render()];
        }

        return $this->verifyCatalogAccess($apiKey, $apiUrl);
    }

    /**
     * Ask the API whether a catalogue sync from this store would be accepted:
     * API key, store address and sync key, the same checks a real sync gets,
     * with nothing written. /health alone cannot tell, so without this a
     * missing sync key only showed up as an empty catalogue.
     */
    private function verifyCatalogAccess(string $apiKey, string $apiUrl): array
    {
        $this->curl->setTimeout(self::TIMEOUT);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-IDEA89-Key', $apiKey);
        $this->addDomainHeader();
        $this->addSyncKeyHeader();
        $this->curl->post($apiUrl . '/v1/catalog/verify', '{}');

        $status = $this->curl->getStatus();
        // 404: an API older than this module; the health check already passed.
        if ($status === 200 || $status === 404) {
            return ['ok' => true];
        }

        $body = (string) $this->curl->getBody();
        $this->logger->warning('IDEA89: catalogue access check failed', [
            'status' => $status,
            'response' => substr($body, 0, 500),
        ]);
        $syncKeyMessage = $this->syncKeyErrorMessage($status, $body);
        if ($syncKeyMessage !== null) {
            return ['ok' => false, 'error' => __('Connected, but catalogue sync will be refused: %1', $syncKeyMessage)->render()];
        }
        $data = json_decode($body, true);
        $code = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : (string) $status;
        return ['ok' => false, 'error' => __('Connected, but the API refused this store (%1). Check your API key.', $code)->render()];
    }
}
