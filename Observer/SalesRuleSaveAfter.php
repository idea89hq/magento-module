<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Client\Idea89Client;

/**
 * Pushes a single cart price rule to the API when it is saved or deleted.
 * Only rules with coupon_type=SPECIFIC_COUPON (2) and a non-empty coupon_code
 * are synced — auto-applied rules have no code to surface in the widget.
 */
class SalesRuleSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config        $config,
        private readonly Idea89Client  $client,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $apiKey = $this->config->getApiKey();
        $apiUrl = $this->config->getApiUrl();
        if (!$apiKey || !$apiUrl) {
            return;
        }

        /** @var \Magento\SalesRule\Model\Rule $rule */
        $rule = $observer->getEvent()->getRule();
        if (!$rule) {
            return;
        }

        // Skip rules without a specific coupon code
        if ((int) $rule->getCouponType() !== \Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC) {
            return;
        }
        $code = trim((string) $rule->getPrimaryCoupon()->getCode());
        if ($code === '') {
            return;
        }

        $toDate = $rule->getToDate();
        $isDelete = $observer->getEvent()->getName() === 'sales_rule_delete_after';
        $payload = [
            'external_id' => (string) $rule->getId(),
            'code'        => $code,
            'description' => $rule->getName() ?: $code,
            'expires_at'  => $toDate ? date('c', strtotime($toDate . ' 23:59:59')) : null,
            'is_active'   => $isDelete ? false : (bool) $rule->getIsActive(),
        ];

        $result = $this->client->upsertPromos([$payload], $apiKey, $apiUrl);
        if (!$result) {
            $this->logger->error('Idea89: failed to sync promo rule ' . $rule->getId());
        }
    }
}
