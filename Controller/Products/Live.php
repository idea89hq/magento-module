<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Products;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Idea89\Assistant\Model\PersonalizationConfig;
use Idea89\Assistant\Model\Sync\ProductSerializer;

/**
 * POST /idea89/products/live
 * Auth: Authorization: Bearer <signing_secret> (server-to-server from IDEA89).
 * No customer session involved. Three request shapes:
 *
 *   { "skus": ["A","B", ...] }        live price/stock for ≤25 SKUs, so the
 *                                     backend can confirm cards it is about
 *                                     to render (unchanged since 1.1)
 *   { "sku": "A", "full": true }      one product, the same payload as the
 *                                     catalogue sync ({"product": {...}})
 *   { "search": "words", "limit": 5 } enabled, visible products whose name
 *                                     contains the words, same payload
 *                                     ({"products": [...]}, at most 10)
 */
class Live implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly PersonalizationConfig $config,
        private readonly ProductSerializer $serializer,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
    ) {}

    private const SEARCH_MAX = 10;

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);

        $secret = $this->config->getSigningSecret();
        $auth = (string) $this->request->getHeader('Authorization');
        if ($secret === '' || !hash_equals('Bearer ' . $secret, $auth)) {
            return $result->setHttpResponseCode(401)->setData(['error' => 'unauthorized']);
        }

        $body = json_decode((string) $this->request->getContent(), true);
        if (!is_array($body)) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'invalid_request']);
        }
        if (is_string($body['sku'] ?? null) && !empty($body['full'])) {
            return $result->setData(['product' => $this->fullProduct((string) $body['sku'])]);
        }
        if (is_string($body['search'] ?? null)) {
            $limit = max(1, min(self::SEARCH_MAX, (int) ($body['limit'] ?? 5)));
            return $result->setData(['products' => $this->search((string) $body['search'], $limit)]);
        }
        $skus = is_array($body['skus'] ?? null) ? array_slice($body['skus'], 0, 25) : [];
        $out = [];
        foreach ($skus as $sku) {
            try {
                $p = $this->productRepo->get((string) $sku);
                $stock = $this->stockRegistry->getStockItem($p->getId());
                $out[] = [
                    'sku'      => $p->getSku(),
                    // The price the sync sends (logged-out shopper, rules applied,
                    // a configurable from its children): a configurable's own
                    // final price is the display amount, with tax when prices
                    // are shown including it. Group/tier pricing not applied.
                    'price'    => $this->serializer->shopperPrice($p, $p->getStore()) ?? (float) $p->getFinalPrice(),
                    'qty'      => (float) $stock->getQty(),
                    'in_stock' => (bool) $stock->getIsInStock(),
                    'status'   => (int) $p->getStatus(),
                    'url'      => $p->getProductUrl(),
                ];
            } catch (\Throwable $e) {
                // SKU missing/deleted — omit; backend falls back to synced value.
            }
        }
        return $result->setData(['products' => $out]);
    }

    /** One enabled, visible product by SKU in the sync payload, or null. */
    private function fullProduct(string $sku): ?array
    {
        try {
            $p = $this->productRepo->get($sku);
        } catch (\Throwable $e) {
            return null;
        }
        if ((int) $p->getStatus() !== Status::STATUS_ENABLED || (int) $p->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
            return null;
        }
        return $this->serializer->serialize($p);
    }

    /** @return array<int, array<string, mixed>> */
    private function search(string $text, int $limit): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim(mb_substr($text, 0, 120))) ?: [], static fn (string $w): bool => mb_strlen($w) >= 2));
        if ($words === []) {
            return [];
        }
        $this->searchCriteriaBuilder
            ->addFilter('status', Status::STATUS_ENABLED)
            ->addFilter('visibility', [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH], 'in');
        // Every word in the name (filters on separate calls are ANDed).
        foreach (array_slice($words, 0, 6) as $w) {
            $this->searchCriteriaBuilder->addFilter('name', '%' . addcslashes($w, '%_\\') . '%', 'like');
        }
        $criteria = $this->searchCriteriaBuilder
            ->setPageSize($limit)
            ->setCurrentPage(1)
            ->addSortOrder($this->sortOrderBuilder->setField('name')->setAscendingDirection()->create())
            ->create();
        $out = [];
        foreach ($this->productRepo->getList($criteria)->getItems() as $p) {
            try {
                $out[] = $this->serializer->serialize($p);
            } catch (\Throwable $e) {
                // One product that fails to serialize is left out.
            }
        }
        return $out;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // bearer-authed server call; no form key
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
