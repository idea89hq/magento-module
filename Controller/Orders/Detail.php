<?php

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Orders;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Idea89\Assistant\Model\OrderSanitizer;
use Idea89\Assistant\Model\OrderTrackingConfig;

/**
 * GET /idea89/orders/detail?increment_id=NNNN
 *
 * Returns one logged-in customer's order in detail shape (items +
 * tracking entries included). Used when the shopper picks one of the
 * orders from the recent-list UI.
 *
 * Security: rejects with the SAME 404 / order_not_found response for
 * (a) increment_id doesn't exist, (b) order belongs to a different
 * customer, (c) order is on a different storeview. Identical error
 * shapes prevent enumeration of other customers' order numbers via
 * timing or response differentiation.
 */
class Detail implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderSanitizer $sanitizer,
        private readonly OrderTrackingConfig $config,
        private readonly RequestInterface $request
    ) {}

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);

        $origin = (string) ($this->request->getHeader('Origin') ?: '');
        if ($origin !== '') {
            $baseHost = parse_url((string) $this->storeManager->getStore()->getBaseUrl(), PHP_URL_HOST);
            $originHost = parse_url($origin, PHP_URL_HOST);
            if ($originHost !== $baseHost) {
                return $result->setHttpResponseCode(403)
                    ->setData(['error' => 'cross_origin_forbidden']);
            }
        }

        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'feature_disabled']);
        }

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)
                ->setData(['error' => 'not_logged_in']);
        }

        $incrementId = (string) ($this->request->getParam('increment_id') ?? '');
        if ($incrementId === '' || strlen($incrementId) > 32) {
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'order_not_found']);
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $storeId = (int) $this->storeManager->getStore()->getId();

        $criteria = $this->criteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->addFilter('customer_id', $customerId)
            ->addFilter('store_id', $storeId)
            ->setPageSize(1)
            ->create();

        try {
            $list = $this->orderRepository->getList($criteria);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)
                ->setData(['error' => 'lookup_failed']);
        }

        $items = $list->getItems();
        if (empty($items)) {
            // Identical 404 response whether the order doesn't exist OR
            // belongs to someone else OR is on the wrong storeview. No
            // information leaked about the order space.
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'order_not_found']);
        }
        $order = reset($items);

        return $result->setData([
            'order' => $this->sanitizer->sanitize($order, detail: true),
        ]);
    }
}
