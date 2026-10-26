<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Idea89\Assistant\Model\Config;

/**
 * GET /idea89/product/gallery?id=<entity_id>  (or ?sku=<sku>)
 *
 * A product's image gallery, for the chat's image viewer. The widget asks for
 * it only when a shopper presses the enlarge button on a product card, so a
 * conversation full of cards costs nothing until someone wants a closer look.
 *
 * Returns {images: [{url, label}]}: the enabled gallery images in the
 * merchant's order, at the theme's large product-page size, videos left out.
 * Same gates as the mini PDP (Product/Mini.php): module enabled, the product
 * shown in this store view and assigned to this website, so a guessed id of a
 * hidden product answers 404 and nothing more.
 */
class Gallery implements HttpGetActionInterface
{
    private const MAX_IMAGES = 12;

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly ProductHelper $productHelper,
        private readonly ImageHelper $imageHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {}

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        $sku = trim((string) $this->request->getParam('sku'));
        $id = trim((string) $this->request->getParam('id'));
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        try {
            if ($id !== '' && ctype_digit($id)) {
                $product = $this->productRepo->getById((int) $id, false, $storeId);
            } elseif ($sku !== '') {
                $product = $this->productRepo->get($sku, false, $storeId);
            } else {
                return $result->setHttpResponseCode(404)->setData(['error' => 'not_found']);
            }
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        if (!$this->productHelper->canShow($product)
            || !in_array((int) $store->getWebsiteId(), array_map('intval', (array) $product->getWebsiteIds()), true)) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        $images = [];
        foreach ($this->galleryEntries($product) as $entry) {
            if (count($images) >= self::MAX_IMAGES) {
                break;
            }
            $file = (string) ($entry['file'] ?? '');
            if ($file === '' || !empty($entry['disabled']) || ($entry['media_type'] ?? 'image') !== 'image') {
                continue;
            }
            try {
                $url = $this->imageHelper->init($product, 'product_page_image_large')->setImageFile($file)->getUrl();
            } catch (\Throwable $e) {
                continue;
            }
            $images[] = ['url' => (string) $url, 'label' => trim((string) ($entry['label'] ?? ''))];
        }

        // Read-only and the same for every shopper: short public caching.
        $result->setHeader('Cache-Control', 'public, max-age=300', true);
        return $result->setData(['images' => $images]);
    }

    /**
     * The gallery entries in the merchant's position order.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return array<int, array<string, mixed>>
     */
    private function galleryEntries($product): array
    {
        $gallery = $product->getData('media_gallery');
        $entries = is_array($gallery) && isset($gallery['images']) && is_array($gallery['images']) ? array_values($gallery['images']) : [];
        usort($entries, static fn (array $a, array $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));
        return $entries;
    }
}
