<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Block\Widget;

use Magento\Widget\Block\BlockInterface;
use Idea89\Assistant\Block\Locator as LocatorBlock;

/**
 * CMS widget that embeds the store locator inside any Magento CMS page,
 * static block, or layout XML. Inherits all `getApiBase`, `getLocations`,
 * `getMapProvider`, `getLocatorConfig` accessors from the parent so the
 * embed template can reuse the page template's data wiring.
 *
 * Merchants insert via the WYSIWYG widget picker on any CMS page; the
 * page slug becomes the locator's URL — total flexibility, no router
 * involvement. Layout XML embed also works:
 *
 *     <block class="Idea89\Assistant\Block\Widget\Locator"
 *            name="custom.locator"
 *            template="Idea89_Assistant::widget/locator-embed.phtml"/>
 *
 * The embed template ships WITHOUT the page chrome (hero, help section)
 * because those belong to whatever CMS page is hosting the embed.
 * Merchants who want the chrome should use the standalone /store-finder
 * route instead.
 *
 * If the Locator master toggle is off, the widget renders nothing.
 */
class Locator extends LocatorBlock implements BlockInterface
{
    protected $_template = 'Idea89_Assistant::widget/locator-embed.phtml';

    /**
     * Mirror the standalone controller's enable check so an "online only"
     * merchant who set Enable Store Locator = No gets nothing rendered
     * when the widget block sits in a CMS page. Also short-circuits on
     * the Pro-tier gate (cfg.locatorEnabled) — non-Pro stores rendering
     * this widget get an empty string, matching the storefront page's
     * 404 behaviour for the same plan.
     */
    protected function _toHtml(): string
    {
        if (!$this->getLocatorConfig()->isEnabled()) {
            return '';
        }
        if (!$this->isLocatorPlanEnabled()) {
            return '';
        }
        return parent::_toHtml();
    }
}
