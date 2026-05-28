<?php

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Framework\HTTP\Client\Curl;

/**
 * Single fetcher for the widget's boot-cfg JSON (served at
 * `<api_url>/widget/v1/<api_key>.js`). The cfg endpoint is the canonical
 * source of every per-store flag the storefront needs: map provider key,
 * brand colour, layout, **and locatorEnabled (Pro-tier gate)**.
 *
 * Both the standalone page Controller and the Block render path need
 * `locatorEnabled` for plan-gating, and the Block additionally needs
 * the map / brand / layout fields for rendering. Extracting the fetch
 * here avoids a second curl call per page-load and gives Controller a
 * way to 404 the page BEFORE the locator block is even instantiated.
 *
 * Caching: the parsed cfg is memoised on the request-scoped instance,
 * so multiple consumers in one request share a single curl call.
 *
 * Failure mode: any error (network, malformed JSON, missing key) →
 * the safe fallback (`locatorEnabled => false`, `provider => stadia`).
 * Refusing to render is the right answer for a misconfigured store;
 * the merchant fixes their api_key / api_url and tries again.
 */
class RemoteCfg
{
    /**
     * @var array{
     *   provider: string,
     *   key: string|null,
     *   country: string|null,
     *   count: int,
     *   brandColor: string|null,
     *   storefinderLayout: string,
     *   locatorEnabled: bool
     * }|null
     */
    private ?array $cached = null;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl
    ) {}

    /**
     * @return array{
     *   provider: string,
     *   key: string|null,
     *   country: string|null,
     *   count: int,
     *   brandColor: string|null,
     *   storefinderLayout: string,
     *   locatorEnabled: bool
     * }
     */
    public function get(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $fallback = [
            'provider' => 'stadia',
            'key' => null,
            'country' => null,
            'count' => 3,
            'brandColor' => null,
            'storefinderLayout' => 'fullwidth',
            // Default to FALSE so a misconfigured / non-Pro store gets
            // the locator 404 instead of accidentally rendering an empty
            // page that fails to load any locations. Pro+enterprise stores
            // get `true` from the cfg payload.
            'locatorEnabled' => false,
        ];

        $apiUrl = rtrim($this->config->getApiUrl(), '/');
        $apiKey = $this->config->getApiKey();
        if ($apiUrl === '' || $apiKey === '') {
            return $this->cached = $fallback;
        }

        try {
            $this->curl->setTimeout(2);
            $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 1);
            $this->curl->get($apiUrl . '/widget/v1/' . $apiKey . '.js');
            $body = $this->curl->getBody();
            // The endpoint returns JS like `var cfg = {...}`. Same pattern
            // used by Model/Csp/WidgetWhitelist.php.
            if (
                preg_match('/var cfg = (\{[\s\S]*?\});/', $body, $m)
                && is_array($cfg = json_decode($m[1], true))
            ) {
                return $this->cached = [
                    'provider' => is_string($cfg['mapProvider'] ?? null) ? $cfg['mapProvider'] : 'stadia',
                    'key' => is_string($cfg['mapKey'] ?? null) ? $cfg['mapKey'] : null,
                    'country' => is_string($cfg['defaultCountryCode'] ?? null) ? $cfg['defaultCountryCode'] : null,
                    'count' => is_int($cfg['nearestResultsCount'] ?? null) ? $cfg['nearestResultsCount'] : 3,
                    'brandColor' => is_string($cfg['brandColor'] ?? null) ? $cfg['brandColor'] : null,
                    'storefinderLayout' => (is_string($cfg['storefinderLayout'] ?? null)
                        && in_array($cfg['storefinderLayout'], ['fullwidth', 'boxed'], true))
                        ? $cfg['storefinderLayout'] : 'fullwidth',
                    'locatorEnabled' => is_bool($cfg['locatorEnabled'] ?? null)
                        ? $cfg['locatorEnabled'] : false,
                ];
            }
        } catch (\Throwable $e) {
            // network/timeout → fall through to fallback (locatorEnabled=false)
        }

        return $this->cached = $fallback;
    }

    /**
     * Convenience accessor for the Pro-tier gate. Returns false for any
     * non-Pro plan, network failure, or misconfigured tenant — failing
     * closed is the right answer for a billing-tier check.
     */
    public function isLocatorPlanEnabled(): bool
    {
        return $this->get()['locatorEnabled'];
    }
}
