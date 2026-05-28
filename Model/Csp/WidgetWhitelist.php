<?php
declare(strict_types=1);

namespace Idea89\Assistant\Model\Csp;

use Idea89\Assistant\Model\Config;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Dynamically whitelists the IDEA89 API + widget hosts in the store's Content
 * Security Policy.
 *
 * A static csp_whitelist.xml can only declare one fixed host. This collector
 * always allows the live IDEA89 API (Config::DEFAULT_API_URL) and additionally
 * whatever api_url / widget_url the store is configured with — covering a custom
 * API host or a localhost URL during development. Without this, a store running
 * an enforced (non report-only) CSP would block the widget from loading or from
 * talking to the API.
 *
 * When the tenant's widget config has mapProvider === "google", the collector
 * additionally whitelists the Google Maps API hosts across script-src, connect-src,
 * and img-src. Stadia tenants' CSP is unchanged (Phase 6 behaviour preserved).
 */
class WidgetWhitelist implements PolicyCollectorInterface
{
    /** @var string|null Cached map provider ('stadia' | 'google') for this request */
    private ?string $cachedMapProvider = null;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
    ) {
    }

    /**
     * @param \Magento\Csp\Api\Data\PolicyInterface[] $defaultPolicies
     * @return \Magento\Csp\Api\Data\PolicyInterface[]
     */
    public function collect(array $defaultPolicies = []): array
    {
        $policies = $defaultPolicies;

        // Always allow the live API, plus this store's configured API/widget
        // hosts (custom host, or localhost in development). Deduped.
        $hosts = array_values(array_unique(array_filter([
            $this->hostSource(Config::DEFAULT_API_URL),
            $this->hostSource($this->config->getApiUrl()),
            $this->hostSource($this->config->getWidgetUrl()),
        ])));

        if ($hosts) {
            // connect-src — the widget's fetch() calls: /v1/chat, /v1/chat/cart,
            // /v1/chat/click, the can-show check, and feedback.
            $policies[] = new FetchPolicy('connect-src', false, $hosts);
            // script-src — the <script src="…/widget/v1/{apiKey}.js"> tag.
            $policies[] = new FetchPolicy('script-src', false, $hosts);
            // img-src — Leaflet map tiles loaded by the store-locator widget view.
            $policies[] = new FetchPolicy('img-src', false, array_merge($hosts, ['https://tiles.stadiamaps.com']));
        }

        // Phase 7 (Task 10): conditionally add Google Maps hosts ONLY when the
        // tenant has opted in to the Google Maps provider. Stadia tenants are
        // unaffected — no extra CSP directives are added for them.
        if ($this->getMapProvider() === 'google') {
            // Google Maps JS API loads from googleapis.com; tile images from gstatic.com;
            // user content (place photos etc.) from googleusercontent subdomains.
            $policies[] = new FetchPolicy(
                'script-src',
                false,
                ['https://maps.googleapis.com', 'https://maps.gstatic.com']
            );
            $policies[] = new FetchPolicy(
                'connect-src',
                false,
                ['https://maps.googleapis.com']
            );
            $policies[] = new FetchPolicy(
                'img-src',
                false,
                ['https://maps.gstatic.com', 'https://*.googleusercontent.com']
            );
        }

        return $policies;
    }

    /**
     * Returns 'google' or 'stadia' for the current tenant. Reads the live widget
     * config (which serves the boot-signal JSON the chat widget consumes anyway).
     * Cached in-memory for the request. On any error, falls back to 'stadia'.
     */
    private function getMapProvider(): string
    {
        if ($this->cachedMapProvider !== null) {
            return $this->cachedMapProvider;
        }
        $apiKey = (string) $this->config->getApiKey();
        if ($apiKey === '') {
            return $this->cachedMapProvider = 'stadia';
        }
        $apiUrl = rtrim((string) $this->config->getApiUrl(), '/');
        if ($apiUrl === '') {
            return $this->cachedMapProvider = 'stadia';
        }
        try {
            $this->curl->setTimeout(1);
            $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 1);
            $this->curl->get($apiUrl . '/widget/v1/' . $apiKey . '.js');
            $body = $this->curl->getBody();
            if (preg_match('/"mapProvider"\s*:\s*"google"/', $body)) {
                return $this->cachedMapProvider = 'google';
            }
        } catch (\Throwable $e) {
            // network / timeout — fall back to stadia (safe default; no CSP relaxation)
        }
        return $this->cachedMapProvider = 'stadia';
    }

    /**
     * Reduce a configured URL to a "scheme://host[:port]" CSP host-source.
     */
    private function hostSource(string $url): string
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return '';
        }
        $source = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $source .= ':' . $parts['port'];
        }
        return $source;
    }
}
