<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\HTTP\Client\Curl;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\LocatorConfig;
use Idea89\Assistant\Model\RemoteCfg;

class Locator extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly LocatorConfig $locatorConfig,
        private readonly RemoteCfg $remoteCfg,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Typed accessor for all merchant-configurable locator copy
     * (hero text, help section, page meta, layout, URL slug).
     * Template reads `$block->getLocatorConfig()->getHeroH1()` etc.
     */
    public function getLocatorConfig(): LocatorConfig
    {
        return $this->locatorConfig;
    }

    public function getApiBase(): string
    {
        // Browser-facing widget URL. Falls back to api_url if widget_url
        // isn't configured.
        return rtrim($this->config->getWidgetUrl() ?: $this->config->getApiUrl() ?: '', '/');
    }

    public function getApiKey(): string
    {
        return (string) $this->config->getApiKey();
    }

    /**
     * Map provider for this tenant — 'stadia' (default) or 'google'.
     * Now delegates to RemoteCfg so the same parsed cfg drives the page
     * Controller's plan-gate check (Pro-only) AND the locator's render
     * fields (provider key, brand color, layout). Single HTTP call per
     * request, shared by all consumers.
     */
    private function getMapCfg(): array
    {
        return $this->remoteCfg->get();
    }

    /**
     * Pro-tier gate — derived from cfg.locatorEnabled (server-side flag
     * set by the API for pro/enterprise stores with seeded locations).
     * Exposed publicly so the CMS widget block can render nothing on
     * non-Pro tenants; the page Controller checks via RemoteCfg directly.
     */
    public function isLocatorPlanEnabled(): bool
    {
        return $this->remoteCfg->isLocatorPlanEnabled();
    }

    public function getMapProvider(): string
    {
        return $this->getMapCfg()['provider'];
    }

    public function getMapKey(): ?string
    {
        return $this->getMapCfg()['key'];
    }

    public function getDefaultCountryCode(): ?string
    {
        return $this->getMapCfg()['country'];
    }

    /**
     * Brand colour fallback chain: Magento admin override (widget/brand_color)
     * → dashboard cfg JSON brandColor → IDEA89 emerald (handled in template).
     * Per the locked design rule "Magento overrides dashboard when non-empty".
     */
    public function getBrandColor(): ?string
    {
        $override = $this->config->getBrandColor();
        if ($override !== '') {
            return $override;
        }
        return $this->getMapCfg()['brandColor'];
    }

    /**
     * Layout fallback chain: Magento Locator → dashboard cfg JSON → fullwidth.
     * `getLayoutOverride()` returns '' when the merchant left it on
     * "Use dashboard setting", which is the signal to defer.
     */
    public function getStorefinderLayout(): string
    {
        $override = $this->locatorConfig->getLayoutOverride();
        if ($override !== '') {
            return $override;
        }
        return $this->getMapCfg()['storefinderLayout'];
    }

    public function getNearestResultsCount(): int
    {
        return $this->getMapCfg()['count'];
    }

    /**
     * Server-side fetch of locations for JSON-LD emission AND the
     * storefront hero's coverage stats. Uses the unbounded /locations
     * endpoint (active rows, up to 1000) so the stats reflect every
     * showroom — the older /nearest path was capped at 10. Cached in
     * Magento's Full-Page Cache via the lifetime/key methods below.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLocations(): array
    {
        $apiUrl = rtrim($this->config->getApiUrl() ?: '', '/');
        if ($apiUrl === '') return [];
        $url = $apiUrl . '/widget/v1/locations';
        try {
            $this->curl->setTimeout(5);
            $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 3);
            $this->curl->addHeader('X-IDEA89-Key', $this->getApiKey());
            $parsed = parse_url((string) $this->_storeManager->getStore()->getBaseUrl());
            $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
            $this->curl->addHeader('Origin', $origin);
            $this->curl->get($url);
            $body = $this->curl->getBody();
            $data = json_decode($body, true);
            if (!is_array($data) || !isset($data['locations']) || !is_array($data['locations'])) {
                return [];
            }
            return $data['locations'];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build a schema.org Store JSON-LD blob for one location.
     *
     * @param array<string, mixed> $loc
     */
    public function renderStoreJsonLd(array $loc): string
    {
        $addr = is_array($loc['address'] ?? null) ? $loc['address'] : [];
        $geo = is_array($loc['geo'] ?? null) ? $loc['geo'] : [];
        $hours = [];

        if (isset($loc['hours']['regular']) && is_array($loc['hours']['regular'])) {
            $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            foreach ($loc['hours']['regular'] as $dayIdx => $range) {
                if (!is_string($range) || !str_contains($range, '–')) continue;
                [$open, $close] = explode('–', $range, 2);
                $dayIdxInt = (int) $dayIdx;
                if ($dayIdxInt < 0 || $dayIdxInt > 6) continue;
                $hours[] = [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => $dayNames[$dayIdxInt],
                    'opens' => $open,
                    'closes' => $close,
                ];
            }
        }

        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();
        $payload = [
            '@context' => 'https://schema.org',
            '@type' => 'Store',
            '@id' => $baseUrl . 'store-finder#' . rawurlencode((string) ($loc['external_id'] ?? '')),
            'name' => $loc['name'] ?? '',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => trim(((string) ($addr['line_1'] ?? '')) . ' ' . ((string) ($addr['line_2'] ?? ''))),
                'addressLocality' => $addr['city'] ?? '',
                'postalCode' => $addr['postcode'] ?? '',
                'addressCountry' => $addr['country_code'] ?? '',
            ],
            'geo' => [
                '@type' => 'GeoCoordinates',
                'latitude' => $geo['lat'] ?? null,
                'longitude' => $geo['lng'] ?? null,
            ],
            'telephone' => $loc['phone'] ?? null,
            'url' => $loc['url'] ?? null,
            'openingHoursSpecification' => $hours,
        ];
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function getCacheLifetime(): int
    {
        return 900; // 15 minutes — matches design doc §7 cache strategy.
    }

    /**
     * @return array<int, string>
     */
    public function getCacheKeyInfo(): array
    {
        return array_merge(parent::getCacheKeyInfo(), [
            'IDEA89_LOCATOR',
            $this->getApiKey(),
            // Include map provider so flipping it in the dashboard busts the
            // FPC entry — otherwise a stadia-cached page would persist for up
            // to 15 minutes after switching to Google.
            'mp:' . $this->getMapProvider(),
            // 8-char hash of every merchant-configurable locator field —
            // ANY change to hero text, layout, URL slug etc. produces a new
            // cache key, so FPC entries auto-invalidate on save. Avoids
            // wiring up a model_config_data_save_after observer.
            'lv:' . $this->locatorConfig->getContentVersion(),
        ]);
    }
}
