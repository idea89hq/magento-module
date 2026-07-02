<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Product;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Result\Page as ResultPage;
use Magento\Framework\Registry;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Store\Model\StoreManagerInterface;
use Idea89\Assistant\Model\Config;

/**
 * GET /idea89/product/mini?sku=<sku>  (or ?id=<entity_id>)
 *
 * Renders a *real* Magento product-view page — the same default layout blocks a
 * PDP uses (media gallery, price, configurable swatches, add-to-cart form) and
 * their JS — but with the site chrome (header/footer/breadcrumbs/etc.) stripped
 * via the `idea89_product_mini` layout handle. The chat widget loads this in a
 * contained popup, so add-to-cart and swatches behave exactly like the
 * storefront PDP (same session, same form key, same AJAX). No bespoke template:
 * whatever the merchant's theme renders on a PDP is what appears here.
 *
 * We register the product and add the catalog_product_view handles ourselves
 * (rather than Catalog's view helper) because that helper keys its handles off
 * THIS controller's action name and triggers layout generation before the real
 * product-view handle can be added.
 *
 * Public GET, gated on the module being enabled. Same-origin framing only.
 */
class Mini implements HttpGetActionInterface
{
    public function __construct(
        private readonly ResultFactory $resultFactory,
        private readonly PageFactory $pageFactory,
        private readonly RequestInterface $request,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly Registry $registry,
        private readonly EventManager $eventManager,
        private readonly Config $config,
        private readonly ProductHelper $productHelper,
        private readonly StoreManagerInterface $storeManager
    ) {}

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            return $this->notFound();
        }

        $sku = trim((string) $this->request->getParam('sku'));
        $id  = trim((string) $this->request->getParam('id'));

        $store   = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        try {
            // Load in the CURRENT store scope so status/visibility reflect the
            // store view (not admin/default scope).
            if ($sku !== '') {
                $product = $this->productRepo->get($sku, false, $storeId);
            } elseif ($id !== '') {
                $product = $this->productRepo->getById((int) $id, false, $storeId);
            } else {
                return $this->notFound();
            }
        } catch (\Throwable $e) {
            return $this->notFound();
        }

        // Same visibility gate as the real PDP: enabled + not "Not Visible
        // Individually" (blocks a configurable's child simples, search-only
        // products, etc. — so guessing a hidden SKU/id returns 404).
        if (!$this->productHelper->canShow($product)) {
            return $this->notFound();
        }

        // Website membership: don't render a product that isn't assigned to the
        // current website (multi-website installs).
        $websiteId = (int) $store->getWebsiteId();
        if (!in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true)) {
            return $this->notFound();
        }

        // Several product-view blocks (e.g. the review form) read the product id
        // straight from the request, which a ?sku= lookup doesn't carry. Inject
        // it so they resolve the product exactly like the real PDP route.
        $params = $this->request->getParams();
        $params['id'] = (string) $product->getId();
        $this->request->setParams($params);

        // Blocks read the product from the registry (same keys the PDP uses).
        $this->registry->register('current_product', $product, true);
        $this->registry->register('product', $product, true);

        /** @var ResultPage $page */
        $page = $this->pageFactory->create();

        // Add every handle BEFORE the layout is generated (it's generated when
        // the page result renders, after this controller returns):
        //  - catalog_product_view: the real product-view blocks (gallery, price,
        //    options, add-to-cart form).
        //  - the type/id/sku variants: configurable swatches + per-product XML.
        //  - idea89_product_mini: strips the site chrome.
        $page->addHandle('catalog_product_view');
        $page->addPageLayoutHandles(
            [
                'type' => $product->getTypeId(),
                'id'   => (string) $product->getId(),
                'sku'  => $product->getSku(),
            ],
            'catalog_product_view',
            false
        );
        $page->addHandle('idea89_product_mini');
        $page->getConfig()->setPageLayout('1column');
        $page->setHeader('Cache-Control', 'no-store', true);

        // Let modules that decorate the PDP (swatches, MSI, etc.) run.
        $this->eventManager->dispatch('catalog_controller_product_view', ['product' => $product]);

        return $page;
    }

    private function notFound(): ResultInterface
    {
        $raw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $raw->setHttpResponseCode(404)->setContents('');
        return $raw;
    }
}
