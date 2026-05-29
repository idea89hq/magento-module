# Order Tracking — Merchant Guide

> Shipped in `idea89/magento2-assistant` v1.1.1 (29 May 2026). Included on **every IDEA89 plan** — no upgrade required.

Order tracking lets shoppers find and follow their orders without leaving the chat. When someone asks "where is my order?", "track my parcel", "did my order ship?", or any clear variant, the assistant surfaces a compact order card right inside the chat widget — no popup, no page navigation.

This page covers what you need to know to install, configure, and troubleshoot.

---

## What shoppers see

### Logged-in customers

1. Shopper types `where is my order?` (or similar) in the chat
2. Assistant replies with one short acknowledgement: _"One moment, I'll pull that up for you."_
3. A card lands in the chat showing their **last 3 orders** — one row per order with status pill, order number, total, and date
4. Tap any row → the card slides to a detail view with shipping method, items, a **Track parcel** button (when a carrier tracking link exists), and a **Contact support** CTA

### Guests

1. Shopper types the same question
2. Card lands with a simple two-field form: **Order number** and **Email address you ordered with**
3. Submit → detail view loads (same shape as for logged-in users)

If the order isn't found, or the email doesn't match the one on the order, the card shows _"Couldn't find an order with those details"_ — the same response for both cases, so order numbers can't be enumerated.

There's an **× close button** at the bottom-right of every state. Tapping it dismisses the card cleanly; it stays dismissed across page reloads.

---

## Install / update

```bash
composer require idea89/magento2-assistant:^1.1
bin/magento setup:upgrade
bin/magento cache:flush
```

That's it. The new endpoints are wired automatically; no Magento-side migrations to apply.

If you're updating from a prior version of the module, hard-refresh the storefront after `cache:flush` so the browser fetches the new chat-widget bundle.

---

## Configuration

`Stores → Configuration → IDEA89 → Order Tracking`. Five fields:

| Field | Default | What it controls |
|---|---|---|
| **Enable Order Tracking** | Yes | Master toggle. When **No**, the chat assistant doesn't surface an order card and the `/idea89/orders/*` endpoints respond with `feature_disabled`. |
| **Contact Support URL** | `/contact` | Where the "Contact support" button on the order card sends shoppers. Relative path, absolute URL, or `mailto:` link. |
| **Contact Support Button Label** | "Contact support" | Customise to match your tone — e.g. "Talk to us", "Email the team". |
| **Max Recent Orders Shown** | 3 | How many of the most recent orders to show a logged-in customer. 1–10. |
| **Show Carrier Tracking Button** | Yes | When **Yes**, the order card shows a "Track parcel" button that opens the carrier's tracking page in a new tab when a tracking link is available. Set to **No** if you'd rather direct shoppers to the contact-support button only. |

All fields can be scoped per store-view. Online-only retailer? Set **Enable Order Tracking = No** at the store-view level and the card never surfaces for that storefront.

---

## Privacy — how order data flows

The model is intentionally **same-origin only**: order data is fetched from your Magento store directly by the shopper's browser. It never travels through the IDEA89 servers, and the AI model never sees it.

```
Shopper browser ─── /idea89/* ───→ Your Magento store    (customer cookies attached)
       │
       └── api.idea89.com (chat stream) — no order data, ever
```

What the AI model sees during an order-tracking turn:
- The shopper's literal message ("where is my order")
- A signal that the order-tracking flow was triggered
- A one-line acknowledgement to generate

What the AI model does **not** see:
- Order numbers, emails, addresses
- Item names, quantities, totals
- Tracking numbers or carriers
- Payment status

This is a structural privacy guarantee — by the shape of the integration, not a policy promise.

Order tracking uses a lower-cost AI tier for the conversational acknowledgement — the assistant just dispatches the order intent; the widget collects information and renders the order itself, so heavy reasoning is unnecessary.

---

## Carrier tracking links

If the order's shipment record has a carrier code that matches one of these, the **Track parcel** button auto-generates a link to the carrier's public tracking page (opens in a new tab):

UPS · FedEx · DHL · Royal Mail · DPD · USPS · Evri (incl. legacy Hermes) · TNT · Parcelforce · Yodel

If the carrier isn't on this list but the shipment record has an explicit `url`, that URL is used instead. If neither is available, the button is hidden — the shopper still sees the order detail and the Contact support CTA.

To add a new carrier: open an issue at https://github.com/idea89hq/magento-module/issues — the matrix is one line in `Model/TrackingUrlResolver.php`.

---

## Rate limiting

The guest order-lookup endpoint is rate-limited to **10 attempts per IP per hour**. After 10 failed attempts within the window, the next request is rejected with HTTP 429 until the window resets.

A **successful** lookup resets the counter — so a shopper who fat-fingers their order number twice and then enters the correct details doesn't carry those typos forward into the next visit.

10 attempts/hour is generous enough for family members or office shoppers sharing an IP, and tight enough that brute-forcing 8–10 digit order numbers is computationally infeasible (~400 K days per IP at this rate).

---

## What the assistant says

The assistant is instructed to **not** ask the shopper for an order number or email itself — the widget collects those when needed. So the conversation looks like:

> Shopper: where is my order?
>
> Assistant: One moment, I'll pull that up for you.
>
> [card lands in chat]

Or for a guest:

> Shopper: track my parcel
>
> Assistant: Sure — let me find that order.
>
> [card lands with order# + email form]

---

## Troubleshooting

### "I see the form, but it stays there after I close it and reload"

Hard-refresh the storefront once. Older versions of the chat widget didn't persist the dismiss state correctly; the cached bundle needs replacing.

```js
// In your browser DevTools Console:
localStorage.clear(); location.reload(true);
```

After that one-time reset, dismiss-and-refresh keeps the card closed permanently.

### "Track parcel button doesn't appear"

It's hidden when:

- The order has no shipments yet (status is still _Pending_ or _Processing_)
- The order is _Cancelled_ or _Refunded_
- The shipment has no carrier code AND no `url`
- The merchant has set **Show Carrier Tracking Button = No**

The order is still findable — only the button is hidden.

### "Shopper got 'too many attempts'"

They've hit the guest-lookup rate limit (10 per IP per hour). It resets automatically at the top of the next hour, or immediately after a successful lookup. The same person fat-fingering on multiple devices shares the same IP, so the limit can be hit faster than expected — point them at the **Contact support** button.

### "Customers are logged in but see the guest form"

Usually one of:

- The customer's session cookies aren't being sent — check that the chat widget is loaded on a same-origin page (not in an iframe with a different origin)
- The session has expired — the widget falls back to the guest form rather than erroring

### "Order Tracking section isn't showing in admin"

Verify:

```bash
bin/magento module:status Idea89_Assistant
# Should show: Module is enabled
```

If you see _Module is disabled_, enable it: `bin/magento module:enable Idea89_Assistant && bin/magento setup:upgrade`.

If the module is enabled but the section is missing, run `bin/magento cache:flush` — the admin config tree caches aggressively.

### "Where is the data coming from?"

Every order-card request goes directly to **your Magento store**, not the IDEA89 servers. The endpoints involved are:

- `GET /idea89/customer/me` — session check (returns first name + email hash only)
- `GET /idea89/orders/recent?limit=3` — logged-in customer's recent orders
- `GET /idea89/orders/detail?increment_id=…` — single order detail
- `POST /idea89/orders/lookup` — guest lookup (email + increment ID)

You can curl them with a logged-in customer's session cookie to verify the response shape.

---

## What's in the order-card response

Every response runs through `OrderSanitizer`. The shape:

```json
{
  "increment_id": "5000003165",
  "placed_at": "2026-05-25T14:22:18Z",
  "status": "shipped",
  "status_label": "Shipped",
  "total_formatted": "£82.40",
  "shipping_method": "FedEx Express",
  "items": [
    { "name": "Carbon road bike pedals", "qty": 1 },
    { "name": "Bar tape (black)", "qty": 2 }
  ],
  "tracking": [
    {
      "carrier": "fedex",
      "carrier_title": "FedEx",
      "number": "8137-2299-4421",
      "url": "https://www.fedex.com/fedextrack/?tracknumbers=…"
    }
  ]
}
```

**Always excluded**: customer ID, full email, billing/shipping addresses, payment information, per-item prices, comment history.

The status field is normalised to one of: `pending`, `processing`, `shipped`, `delivered`, `complete`, `cancelled`, `refunded`, `holding`. The pill colour in the card is driven off this canonical value so it's consistent across stores.

---

## Going further

- **Public module repo**: https://github.com/idea89hq/magento-module
- **Issues / feature requests**: https://github.com/idea89hq/magento-module/issues
- **Packagist**: https://packagist.org/packages/idea89/magento2-assistant
- **Dashboard**: https://app.idea89.com
