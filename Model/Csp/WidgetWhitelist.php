<?php
declare(strict_types=1);

namespace Idea89\Assistant\Model\Csp;

use Idea89\Assistant\Model\Config;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;

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
 */
class WidgetWhitelist implements PolicyCollectorInterface
{
    public function __construct(
        private readonly Config $config
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
        }

        return $policies;
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
