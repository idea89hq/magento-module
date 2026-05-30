<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * PII-safe serializer for Magento order objects. The Pattern A privacy
 * model (see docs/superpowers/specs/2026-05-28-order-tracking-design.md)
 * keeps order data in the shopper's browser ↔ merchant's storefront,
 * but the JSON shape the controllers return still needs to be strictly
 * minimal — even an XSS / open-tab attacker reading the response should
 * only see what the customer is allowed to know about themselves.
 *
 * ALWAYS EXCLUDED:
 *   - customer_id
 *   - full email / billing_email / customer_email
 *   - billing or shipping addresses (any field)
 *   - payment information (method, last4, transaction IDs, …)
 *   - per-item prices, discounts, subtotals
 *   - admin comment history / internal notes
 *   - tax / shipping cost breakdowns
 *   - any field starting with `customer_` or `payment_`
 *
 * ALWAYS INCLUDED:
 *   - increment_id (the customer-visible order number)
 *   - placed_at (ISO 8601 UTC)
 *   - status (canonical, see mapStatus)
 *   - status_label (Magento's display string for this status)
 *   - total_formatted (single currency-aware string, no breakdown)
 *   - shipping method title
 *   - item titles + qty (no prices, no SKUs, no images)
 *   - tracking entries (carrier + number + URL) — only in detail mode
 */
class OrderSanitizer
{
    /**
     * Canonical statuses surfaced to the widget. Maps from Magento's
     * many internal status codes down to a small, UI-stable set so the
     * status pill has consistent colour semantics across merchants.
     */
    private const STATUS_MAP = [
        'pending'           => 'pending',
        'pending_payment'   => 'pending',
        'payment_review'    => 'holding',
        'holded'            => 'holding',
        'processing'        => 'processing',
        'fraud'             => 'holding',
        'complete'          => 'complete',
        'closed'            => 'refunded',
        'canceled'          => 'cancelled',
    ];

    public function __construct(
        private readonly TrackingUrlResolver $trackingUrlResolver,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sanitize(OrderInterface $order, bool $detail = false): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            /** @var OrderItemInterface $item */
            $items[] = [
                'name' => (string) $item->getName(),
                'qty' => (int) $item->getQtyOrdered(),
            ];
        }

        $createdAt = (string) $order->getCreatedAt();
        // Magento stores created_at in store timezone-stamped string format.
        // Force to ISO 8601 UTC so the widget doesn't need to guess.
        $placedAt = $createdAt !== ''
            ? gmdate('c', strtotime($createdAt))
            : null;

        $status = (string) $order->getStatus();

        $base = [
            'increment_id' => (string) $order->getIncrementId(),
            'placed_at' => $placedAt,
            'status' => self::mapStatus($status),
            'status_label' => (string) ($order->getStatusLabel() ?: ucfirst($status)),
            'total_formatted' => $this->formatTotal($order),
            'item_count' => count($items),
            'shipping_method' => (string) ($order->getShippingDescription() ?? ''),
        ];

        if (!$detail) {
            // List view — slim shape, no items, no tracking. Status colour
            // pill + identifier is enough for the picker UX.
            return $base;
        }

        // Detail view — add the line items + tracking blob, still no
        // prices, no SKUs, no addresses.
        $base['items'] = $items;
        $base['tracking'] = $this->extractTracking($order);
        return $base;
    }

    /**
     * @return array<int, array{carrier:string,carrier_title:string,number:string,url:?string}>
     */
    private function extractTracking(OrderInterface $order): array
    {
        $tracking = [];
        foreach ($order->getTracksCollection() as $track) {
            $carrier = (string) $track->getCarrierCode();
            $number = (string) $track->getTrackNumber();
            if ($number === '') {
                continue;
            }
            $explicitUrl = method_exists($track, 'getUrl')
                ? (string) $track->getUrl()
                : '';
            $tracking[] = [
                'carrier' => strtolower($carrier),
                'carrier_title' => (string) ($track->getTitle() ?: $carrier),
                'number' => $number,
                'url' => $explicitUrl !== ''
                    ? $explicitUrl
                    : $this->trackingUrlResolver->resolve($carrier, $number),
            ];
        }
        return $tracking;
    }

    private function formatTotal(OrderInterface $order): string
    {
        $grand = $order->getGrandTotal();
        if (!is_numeric($grand)) {
            return '';
        }
        // PriceCurrency formats with the order's currency code, locale-aware.
        return (string) $this->priceCurrency->format(
            (float) $grand,
            false, // no enclosing tags — plain text
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            (string) $order->getOrderCurrencyCode()
        );
    }

    /**
     * Map Magento's status code → one of the small set the widget
     * understands. Unknown → 'processing' so the UI always renders.
     */
    public static function mapStatus(string $code): string
    {
        $key = strtolower($code);
        return self::STATUS_MAP[$key] ?? 'processing';
    }
}
