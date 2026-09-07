# Agentic Commerce: Merchant Guide

Agentic commerce is when an AI shopping assistant, such as ChatGPT, browses and, on stores that support it, buys products on a shopper's behalf, without the shopper visiting your site directly. IDEA89 publishes your catalog to these assistants using the Agentic Commerce Protocol (ACP), an open specification for how a store describes what it sells and, separately, how it accepts an agent-placed order.

This page covers what the Agentic Commerce settings do, exactly what they expose, how to check your feed, and how to let agents place orders if you want that.

---

## What turning it on does, and does not, expose

`Stores → Configuration → IDEA89 → Checkout Experience → Agentic Commerce`, field **Let AI agents shop your store**. Off by default.

Turning it on publishes a read-only product feed. The feed includes, per product:

- Title and description
- A link to the product page
- An image
- Price
- Availability (`in_stock` or `out_of_stock`)
- Brand, when your catalog has one set

It does **not** include:

- Cost price, margin, or any supplier data
- Customer accounts, addresses, or order history
- Exact stock quantity, unless that quantity is already at or below the low-stock threshold you've configured for your storefront, in which case the feed shows the same figure your product page already shows (for example "3 left"). Above that threshold, no quantity is published at all, matching what a shopper sees on the page itself.

A product whose price cannot be resolved is left out of the feed entirely. IDEA89 never publishes a product at a price of zero.

Turning this setting on does **not** let any agent place an order, view your orders, or access a customer account. That is a separate capability, covered under "Letting agents buy" below, and it is off by default on every store until you install a separate module for it.

There is a second toggle, **Publish the Product Feed**, nested under the first. This lets you turn Agentic Commerce on in general (for example, to prepare the checkout-session routes below) while keeping the feed itself off. Most merchants can leave this on.

An optional **Agent Access Token** field lets you require a bearer token on every request. Leave it blank to serve the feed publicly, which is reasonable since it only republishes what is already on your product pages. Set a token if you'd rather only work with agents you've explicitly given it to.

---

## The feed URL, and how to check it

Your feed is at:

```
https://<your-domain>/idea89/acp/feed.json
```

Check it with curl. Without a token configured:

```bash
curl https://your-store.example.com/idea89/acp/feed.json
```

With a token configured:

```bash
curl -H "Authorization: Bearer <your token>" \
     https://your-store.example.com/idea89/acp/feed.json
```

A working feed returns JSON with a `products` array, plus `page`, `page_size`, and `total`. It is paginated: pass `?page=2` for the next page once you've read the first. If the feed is off, or the store is misconfigured, you'll get a JSON `error` field and a 4xx status instead, never an empty products array pretending everything is fine.

The status panel above these settings in the admin also shows your feed URL directly, once both **Let AI agents shop your store** and **Publish the Product Feed** are on.

---

## Letting agents buy: install Magebit's module

IDEA89 deliberately does not implement agent-initiated checkout itself. Magebit publishes a free, open-source Magento 2 module that already does this well: checkout sessions, delegated payments, and the webhook handling an agent needs to complete a purchase. Reimplementing that alongside IDEA89 would mean maintaining a second, competing version of the same thing, so IDEA89 hands off to it instead.

With Magebit's module installed and enabled, IDEA89 redirects checkout-session requests straight to it and steps out of the way. Without it, an agent that tries to start a checkout session gets a clear, documented response telling it that this store publishes a catalog but does not accept agent-initiated checkout yet, rather than a broken or ambiguous one.

To install it, per Magebit's own instructions:

```bash
composer require magebitcom/magento2-module-agentic-commerce
bin/magento setup:upgrade
bin/magento cache:flush
```

It needs PHP 8.1 or later, same as IDEA89, and a Stripe account for the payment side if you want agents to actually complete a purchase rather than just build a session. Configure it under its own admin section, `Stores → Configuration → Magebit → Agentic Commerce`. That configuration, and anything about how it handles a payment or places an order, is entirely Magebit's own module and its own documentation, not IDEA89's. The status panel in `Checkout Experience` will confirm once IDEA89 has detected it.

---

## This is a beta specification

The Agentic Commerce Protocol is new and still changing. IDEA89 checks the `API-Version` an agent sends against a short list of versions it has actually been verified against, and refuses a version it does not recognise rather than guess at how to serve it. Expect the protocol, and the fields both this module and Magebit's publish, to keep evolving. Check back here before assuming a request that used to fail will keep failing, or that a field described above is guaranteed not to change.

---

## Troubleshooting

### "My feed URL returns `{"error":"not_enabled"}`"

**Let AI agents shop your store** is off. Turn it on under `Stores → Configuration → IDEA89 → Checkout Experience → Agentic Commerce`.

### "My feed URL returns `{"error":"not_available"}`"

Agentic Commerce is on, but **Publish the Product Feed** is off. Turn it on if you want the feed served.

### "My feed URL returns `{"error":"unauthorized"}`"

You've set an **Agent Access Token**, and the request either sent no token or the wrong one. Send `Authorization: Bearer <your token>`, matching exactly what's saved in the field.

### "My feed URL returns `{"error":"unsupported_api_version"}`"

The caller sent an `API-Version` header IDEA89 hasn't been verified against yet. The version currently supported is `2026-04-17`. Omit the header entirely to get that version by default.

### "A checkout-session request returns 501"

This is expected on any store without Magebit's module installed. It means IDEA89 correctly published your catalog but has, on purpose, declined to accept an order itself. See "Letting agents buy" above.

### "A checkout-session request returns 401, and I do have Magebit's module installed"

Authorisation runs before IDEA89 checks whether Magebit's module is present, on purpose, so an unauthorised caller can't tell which state your store is in. Fix the token first; once the request is authorised, it will reach the redirect to Magebit's module.
