<?php

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\LocatorConfig;
use Idea89\Assistant\Model\RemoteCfg;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly Config $config,
        private readonly LocatorConfig $locatorConfig,
        private readonly RemoteCfg $remoteCfg
    ) {
    }

    public function execute(): ResultInterface
    {
        // 404 when:
        //   - the assistant itself is disabled
        //   - no API key set (nothing to query)
        //   - the Locator master toggle is off (online-only merchant)
        //   - the merchant's plan doesn't include Store Locator (Pro+ only).
        //     The plan check goes through RemoteCfg → cfg.locatorEnabled,
        //     which the API sets to true only for pro/enterprise plans with
        //     seeded locations. Fail-closed on any network/parse error.
        // Order matters — local flags first (cheap), remote plan-gate last
        // (one HTTP call, ~2s timeout). Locked together with the storefront
        // /widget/v1/locations API which also returns 403 for non-Pro tenants.
        if (!$this->config->isEnabled()
            || !$this->config->getApiKey()
            || !$this->locatorConfig->isEnabled()
            || !$this->remoteCfg->isLocatorPlanEnabled()
        ) {
            $page = $this->pageFactory->create();
            $page->setStatusHeader(404);
            return $page;
        }

        $page = $this->pageFactory->create();
        // Pull SEO copy from the merchant-configured fields. LocatorConfig
        // applies defaults when blank so we never set an empty title.
        $page->getConfig()->getTitle()->set($this->locatorConfig->getPageTitle());
        $page->getConfig()->setDescription($this->locatorConfig->getMetaDescription());
        return $page;
    }
}
