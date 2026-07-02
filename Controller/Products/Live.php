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
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Idea89\Assistant\Model\PersonalizationConfig;

/**
 * POST /idea89/products/live  { "skus": ["A","B", ...] }
 * Auth: Authorization: Bearer <signing_secret> (server-to-server from IDEA89).
 * Returns live price/stock for ≤25 SKUs so the backend can confirm the cards
 * it is about to render. No customer session involved.
 */
class Live implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly PersonalizationConfig $config
    ) {}

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
        $skus = is_array($body['skus'] ?? null) ? array_slice($body['skus'], 0, 25) : [];
        $out = [];
        foreach ($skus as $sku) {
            try {
                $p = $this->productRepo->get((string) $sku);
                $stock = $this->stockRegistry->getStockItem($p->getId());
                $out[] = [
                    'sku'      => $p->getSku(),
                    // NOTE: default customer-group (group 0) price; group/tier pricing not applied (see Slice C plan).
                    'price'    => (float) $p->getFinalPrice(),
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

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // bearer-authed server call; no form key
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
