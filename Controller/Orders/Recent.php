<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Orders;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Idea89\Assistant\Model\OrderSanitizer;
use Idea89\Assistant\Model\OrderTrackingConfig;

/**
 * GET /idea89/orders/recent[?limit=N]
 *
 * Returns the logged-in customer's most recent N orders as a slim list
 * (see OrderSanitizer::sanitize($order, detail: false) for the shape).
 * 401 if not logged in — the widget falls back to the guest form.
 *
 * Scoping: filter by both customer_id AND store_id so multi-store-view
 * merchants don't leak orders from a sibling storeview. Sort by
 * created_at DESC. limit defaults to OrderTrackingConfig::getMaxRecentOrders()
 * (admin-configurable 1..10), URL ?limit= is clamped to that ceiling.
 */
class Recent implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderSanitizer $sanitizer,
        private readonly OrderTrackingConfig $config,
        private readonly RequestInterface $request
    ) {}

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);

        // Origin guard — see Customer/Me for rationale
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

        $customerId = (int) $this->customerSession->getCustomerId();
        $storeId = (int) $this->storeManager->getStore()->getId();

        $ceiling = $this->config->getMaxRecentOrders();
        $rawLimit = $this->request->getParam('limit');
        $limit = is_numeric($rawLimit) ? (int) $rawLimit : $ceiling;
        $limit = max(OrderTrackingConfig::HARD_MIN_RECENT, min($ceiling, $limit));

        $sort = $this->sortOrderBuilder
            ->setField('created_at')
            ->setDirection(\Magento\Framework\Api\SortOrder::SORT_DESC)
            ->create();

        $criteria = $this->criteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->addFilter('store_id', $storeId)
            ->addSortOrder($sort)
            ->setPageSize($limit)
            ->create();

        try {
            $list = $this->orderRepository->getList($criteria);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)
                ->setData(['error' => 'lookup_failed']);
        }

        $orders = [];
        foreach ($list->getItems() as $order) {
            $orders[] = $this->sanitizer->sanitize($order, detail: false);
        }

        return $result->setData(['orders' => $orders]);
    }
}
