# IDEA89 — AI Shopping Assistant for Magento 2

Turn your Magento storefront into a conversion machine. IDEA89 adds an AI-powered shopping assistant that answers product questions, recommends what to buy, and surfaces promotions — in your brand voice.

**5-minute install. No theme changes. No dev work.**

[![Packagist Version](https://img.shields.io/packagist/v/idea89/magento2-assistant)](https://packagist.org/packages/idea89/magento2-assistant)
[![Packagist Downloads](https://img.shields.io/packagist/dt/idea89/magento2-assistant)](https://packagist.org/packages/idea89/magento2-assistant)
[![Magento 2](https://img.shields.io/badge/Magento-2.4.6+-orange)](https://magento.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-OSL--3.0-green)](LICENSE)

[![Coding Standard](https://github.com/idea89hq/magento-module/actions/workflows/coding-standard.yml/badge.svg)](https://github.com/idea89hq/magento-module/actions/workflows/coding-standard.yml)
[![CodeQL](https://github.com/idea89hq/magento-module/actions/workflows/codeql.yml/badge.svg)](https://github.com/idea89hq/magento-module/actions/workflows/codeql.yml)
[![Security Policy](https://img.shields.io/badge/security-policy-blue)](SECURITY.md)

---

## What it does

| Feature | Description |
|---------|-------------|
| **Smart product recommendations** | AI understands natural language queries like "something waterproof under 100 pounds" and finds the right products from your catalogue |
| **Real-time catalogue sync** | Products, variants, prices, stock levels, and reviews are synced automatically. Out-of-stock items are never recommended |
| **Brand voice** | Configure your assistant's name, tone, and store context. It answers like a member of your team |
| **Promotion awareness** | Active cart price rules are synced so the assistant can surface relevant discounts |
| **In-chat order tracking** _(new in v1.1.1)_ | When a shopper asks "where is my order?" the assistant surfaces a compact order card right in the chat with status, items, and a carrier tracking link. Logged-in customers see their last 3 orders; guests verify with order number + email |
| **Store Locator** _(new in v1.1.0)_ | Physical showroom finder with map, postcode search, hours, photos, and directions — in chat and on a dedicated `/store-finder` page (URL configurable) |
| **Built-in analytics** | Track conversations, conversion rates, and top queries from the merchant dashboard |
| **GDPR-ready** | EU-hosted, no customer data used for AI training, PII redaction before model calls |

## How it works

1. Install the module via Composer
2. Enter your API key from the [IDEA89 dashboard](https://app.idea89.com)
3. The widget appears on your storefront immediately
4. Products sync automatically — the assistant is ready to sell

Your catalogue is indexed with AI embeddings for semantic search. When a shopper asks a question, the assistant searches your products, checks stock, and responds with relevant recommendations — complete with product cards, prices, and add-to-cart buttons.

---

## Requirements

- Magento 2.4.6 or later (Open Source or Commerce)
- PHP 8.2 or 8.3
- An IDEA89 account — [start your free trial](https://app.idea89.com/sign-up)

## Installation

```bash
composer require idea89/magento2-assistant
bin/magento module:enable Idea89_Assistant
bin/magento setup:upgrade
bin/magento cache:flush
```

That's it. No layout XML changes, no theme overrides, no frontend build step.

## Configuration

Navigate to **Stores > Configuration > IDEA89 > AI Shopping Assistant** in Magento Admin.

### General

| Setting | Description |
|---------|-------------|
| **Enable Widget** | Turn the chat widget on/off |
| **API Key** | Your API key from the IDEA89 dashboard (stored encrypted) |
| **Assistant Name** | Name shown in the widget header (e.g. "Aria", "Shop Helper") |
| **Store Context** | Describe what your store sells so the AI can answer general questions |
| **Test Connection** | Verify your API key works |
| **Sync Now** | Manually trigger a full catalogue sync |

### Widget Appearance

| Setting | Description |
|---------|-------------|
| **Position** | Bottom-right or bottom-left |
| **Brand Colour** | Hex code for the widget header (e.g. `#2563eb`) |

### Content Sync

Choose what gets synced to IDEA89:

- **Products** — names, descriptions, prices, images, attributes, variants, stock, reviews
- **Categories** — so the assistant knows your catalogue structure
- **CMS Pages** — About Us, FAQs, policies — the assistant can answer "what's your return policy?"
- **Store Info** — store name and context description

### Store Locator _(Pro plan and above)_

Settings live under **Stores → Configuration → IDEA89 → Store Locator**. Twelve fields covering page behaviour and content:

| Setting | Default | Notes |
|---------|---------|-------|
| **Enable Store Finder Page** | Yes | Master toggle for the locator page and CMS widget |
| **URL Path** | `store-finder` | Pick any slug — `showrooms`, `branches`, `find-a-shop`. Save fails with a clear error if it collides with an existing CMS page, product, category, or module |
| **Page Layout** | Use dashboard setting | Fullwidth (edge-to-edge map) or Boxed (max-width card) |
| **SEO Page Title** + **Meta Description** | (sensible defaults) | Standard SEO control over the page head |
| **Hero Eyebrow / Title / Subhead** | (sensible defaults) | Override the in-page copy without theme edits |
| **Help Section Heading / Body / CTA Label / CTA URL** | "Contact us" → `/contact` | The help section below the map |

The page also lives as a CMS widget — drop **IDEA89 Store Locator** into any CMS page or static block from the widget picker.

Locations themselves are managed in the [IDEA89 dashboard](https://app.idea89.com) → Locator. The chat assistant uses them automatically when a shopper asks "where is your nearest store?"

### Order Tracking _(every plan)_

Settings live under **Stores → Configuration → IDEA89 → Order Tracking**. Five fields:

| Setting | Default | Notes |
|---------|---------|-------|
| **Enable Order Tracking** | Yes | Master toggle. When **No**, the chat assistant won't surface an order card, and the order endpoints respond with `feature_disabled` |
| **Contact Support URL** | `/contact` | Where the "Contact support" button on the order card sends shoppers — relative path, absolute URL, or `mailto:` |
| **Contact Support Button Label** | "Contact support" | Match your tone — "Talk to us", "Email the team" |
| **Max Recent Orders Shown** | 3 | How many recent orders to show a logged-in customer (1–10) |
| **Show Carrier Tracking Button** | Yes | When **Yes**, surfaces a "Track parcel" button when a carrier tracking link is available |

All fields support per-store-view scope. Online-only retailer? Set **Enable Order Tracking = No** on that store-view and the card never surfaces.

See **[docs/order-tracking-guide.md](docs/order-tracking-guide.md)** for the full guide — privacy model, supported carriers, troubleshooting, and the order-card JSON shape.

### Advanced

| Setting | Description |
|---------|-------------|
| **API URL** | Override for self-hosted or enterprise deployments. Leave blank for default. |

## How syncing works

| Trigger | What happens |
|---------|--------------|
| **Product saved** | Changed product is queued and synced within 1 minute |
| **Stock update** | Stock changes are synced within 1 minute |
| **Price rule saved** | Active promotions are synced immediately |
| **Nightly cron** | Full catalogue re-sync as a safety net (configurable) |
| **Manual sync** | Click "Sync Now" in admin to push everything immediately |

All syncs are idempotent — sending the same product twice is safe and expected.

## The widget

The assistant appears as a floating chat widget on your storefront. It includes:

- Conversational AI that understands your products
- Product cards with images, prices, ratings, and add-to-cart buttons
- Promotional banners for active cart price rules
- Quick-reply chips for common questions
- Mobile-responsive design
- Dark/light theme support
- No impact on your Magento theme or page speed (loaded asynchronously)

The widget is served from the IDEA89 CDN — no static content is added to your Magento deployment.

## Pricing

| Plan | Price | Conversations/mo |
|------|-------|-------------------|
| **Free trial** | £0 for 14 days | 100 conversations |
| **Starter** | £49/mo | 1,000 |
| **Growth** | £149/mo | 10,000 |
| **Pro** | £349/mo | 50,000 |

Save 10% with annual billing. All plans include the full feature set.

[Start your free trial](https://app.idea89.com/sign-up) — no credit card required.

## Uninstalling

```bash
bin/magento module:disable Idea89_Assistant
bin/magento setup:upgrade
composer remove idea89/magento2-assistant
bin/magento cache:flush
```

No database tables are created in your Magento instance. All data is stored on the IDEA89 platform.

## Support

- **Documentation:** [idea89.com](https://idea89.com)
- **Email:** support@idea89.com
- **Dashboard:** [app.idea89.com](https://app.idea89.com)

## License

This module is licensed under the [Open Software License 3.0 (OSL-3.0)](https://opensource.org/licenses/OSL-3.0).

Copyright 2026 4K Technologies Ltd.

---

Built by [4K Technologies](https://4ktechnologies.co.uk) in the UK.
