<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Block\Adminhtml\System\Config;

use Idea89\Assistant\Model\Acp\Delegate;
use Idea89\Assistant\Model\CheckoutConfig;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Read-only status panel rendered as the "Agentic Commerce" field, above
 * the acp_enabled toggle, under Stores > Configuration > IDEA89 >
 * Checkout Experience. Tells the merchant in plain language what turning
 * ACP on actually does today: IDEA89 always publishes catalog data
 * (Task 4.3) and never touches payments or order placement (Task 4.4
 * defers to Magebit's module when it is present).
 *
 * Wired via `<frontend_model>` on a `<field>` (not a `<group>`), so this
 * extends Field the way TestConnectionButton / SyncNowButton /
 * TestCheckoutPanelButton do — the label column stays, only the value
 * column's markup is replaced.
 *
 * Renders one of three states, checked in this order:
 *   1. Off        — acp_enabled is No. Nothing is published; say so
 *                    rather than describing a feed that is not served.
 *      (The brief's two documented states assume ACP is already on; this
 *      third state exists so the panel never claims the catalog is
 *      published while the merchant has it switched off — see the
 *      Task 4.1 report for the full reasoning.)
 *   2. Feed only  — acp_enabled is Yes, no transactional module detected.
 *                    Sub-branches on acp_feed_enabled so the panel never
 *                    claims the catalog is published when the merchant
 *                    has separately turned the feed itself off.
 *   3. Full       — acp_enabled is Yes and Delegate detects a
 *                    transactional module (currently Magebit_AgenticCommerce).
 */
class AcpStatus extends Field
{
    private const FEED_PATH = 'idea89/acp/feed.json';
    private const GUIDE_PATH = 'docs/agentic-commerce-guide.md';

    public function __construct(
        Context $context,
        private readonly CheckoutConfig $checkoutConfig,
        private readonly Delegate $delegate,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return '<div class="idea89-acp-status" style="'
            . 'padding:10px 14px;background:#f4f6f5;border:1px solid #dbe3e0;'
            . 'border-radius:4px;font-size:13px;line-height:1.6;color:#333;max-width:640px;">'
            . $this->buildMessage()
            . '</div>';
    }

    private function buildMessage(): string
    {
        if (!$this->checkoutConfig->isAcpEnabled()) {
            return 'Agentic Commerce is off. Turn on <strong>Let AI agents shop your store</strong> '
                . 'below to publish your catalog to AI shopping agents.';
        }

        if ($this->delegate->isTransactionalModuleInstalled()) {
            $moduleName = $this->escapeHtml((string) $this->delegate->getInstalledModuleName());
            return 'Agentic checkout is handled by <code>' . $moduleName . '</code>. '
                . 'IDEA89 publishes your product feed and stays out of the payment path.';
        }

        $installLine = 'To let agents also <em>buy</em> from you, install the free Magebit '
            . 'Agentic Commerce module. See the setup guide at <code>'
            . $this->escapeHtml(self::GUIDE_PATH) . '</code>.';

        if (!$this->checkoutConfig->isAcpFeedEnabled()) {
            return 'Agentic Commerce is on, but the product feed itself is currently off. '
                . 'Turn on <strong>Publish the Product Feed</strong> below to let agents browse '
                . 'your catalog. ' . $installLine;
        }

        $feedUrl = $this->escapeHtml($this->getFeedUrl());
        return 'Your catalog is published for AI agents to browse at <code>' . $feedUrl . '</code>. '
            . $installLine;
    }

    private function getFeedUrl(): string
    {
        $baseUrl = (string) $this->_storeManager->getStore()->getBaseUrl();
        return $baseUrl . self::FEED_PATH;
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }
}
