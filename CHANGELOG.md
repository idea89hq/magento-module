# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-09-07

### Added
- **Checkout experience setting.** A new **Checkout Experience** section,
  Stores → Configuration → IDEA89 → Checkout Experience, adds an
  **Assistant Checkout Mode** field with four options: "Off" sends a
  shopper who says "checkout" to your cart page, exactly as the assistant
  has always behaved; "Express handoff" is the shipped default, it shows a
  basket summary in chat with one button straight to your checkout and
  never touches your checkout itself; "Checkout in chat" opens your own
  Magento checkout, your payment methods, your shipping rules, your
  extensions, inside a panel over the chat; "Native checkout (beta)"
  reimplements the checkout steps inside the chat panel, the assistant asks
  for delivery details itself and places the order. Two more fields,
  **Cart URL Path** (default `/checkout/cart/`) and **Checkout URL Path**
  (default `/checkout/`), let you point the assistant at a non-default cart
  or checkout page, for example a one-step-checkout extension's own URL.
  The storefront now also publishes `window.__IDEA89_PLATFORM` and
  `window.__IDEA89_CHECKOUT` (mode, cart path, checkout path, mini-checkout
  path, form key, checkout bar flag), mirroring the WooCommerce plugin's
  bootstrap contract so the widget uses one detection idiom everywhere.
- **Chrome-free checkout for the assistant panel.** On "Checkout in chat",
  `GET /idea89/checkout/mini` (`Controller/Checkout/Mini.php`) renders your
  real Magento checkout, the same one-page checkout layout, the same
  payment and shipping components, the same third-party extensions, with
  the site header and footer stripped, for the chat widget to frame
  same-origin. It honours your own settings first: one-page checkout must
  be switched on, the basket must have items with no errors, and guest
  checkout must be allowed unless the shopper is signed in. IDEA89 renders
  nothing payment-related and never receives card data. A guard failure
  returns a small same-origin page carrying a machine-readable error code
  instead of redirecting, so the widget can fall back immediately rather
  than waiting out its handshake timeout.
- **Test Checkout Panel.** A new admin action next to Assistant Checkout
  Mode, shown once you select "Checkout in chat", that checks your store
  for anything likely to stop the framed checkout rendering before you
  switch it on: Hyvä Checkout (blocks it outright), five one-step-checkout
  extensions (Amasty, Mageplaza, IWD, Mirasvit, MageDelight), Magento's own
  checkout module being disabled, five payment methods that redirect
  shoppers off-site to pay (PayPal Express Checkout, Braintree PayPal,
  Klarna, Adyen Hosted Payment Pages, Amazon Pay), guest checkout being
  off, and reCAPTCHA on checkout. Run it before going live.
- **postMessage bridge**, matching the WooCommerce and Magento 1 plugins'
  contract exactly: `ready` and `resize` on the framed checkout, `success`
  (with the order number, total and currency) on your real checkout
  success page, and a same-origin error page carrying a machine-readable
  code for every guard failure. The bridge only ever runs inside a frame,
  so an ordinary, unframed checkout visit is completely unaffected by it
  being present.
- **Native checkout (beta) is a real, working rung too.** Selecting it
  lets the assistant collect delivery details in the conversation and
  place the order itself, over four new same-origin routes under
  `/idea89/checkout`: `context` (read the basket), `address`, `method`
  and `place`. The three that change the quote, `address`, `method` and
  `place`, each validate Magento's own form key as their CSRF defence,
  and all four routes are rate-limited on two dimensions at once: per
  shopper session, 8 attempts per 10 minutes for placing an order and 30
  for everything else, and per IP address, 24 and 90 over the same window
  so that shoppers sharing an office or mobile connection do not exhaust
  each other's allowance.
  A new **Payment Methods the Assistant May Use** field is empty by
  default, so a freshly switched-on store places no orders at all until
  you explicitly tick which methods the assistant may use. Methods it
  marks "works in chat" (cheque/money order, bank transfer, cash on
  delivery, purchase order and free orders) complete without leaving the
  conversation; anything else still sends the shopper to your real
  checkout to pay.
- **Agentic Commerce Protocol surface**, off by default. A new
  **Agentic Commerce** area under Checkout Experience opens with a live
  status panel explaining, in plain language, what turning this on does
  for your store right now, then lets AI agents outside the widget,
  ChatGPT and similar, find, and where a separate module supports it, buy
  from your store. **Let AI agents shop your store** defaults to No:
  turning it on publishes your catalog to those third parties, so it is
  never switched on without you choosing it. Once on, **Publish the
  Product Feed** (default Yes) serves your visible catalog, including
  out-of-stock items flagged as such, at `/idea89/acp/feed.json`; disabled
  products, products not visible individually, and products outside the
  current website are never included. An optional **Agent Access Token**
  gates the feed with a bearer token; left blank, the feed is public.
  Five new routes under `/idea89/acp/checkout_sessions` accept agent
  checkout requests, but IDEA89 itself never places an order or touches
  payment: when a separate transactional module is installed (currently
  Magebit's free Agentic Commerce module), the request is redirected to
  it; otherwise every request is declined with a fixed, documented
  message. This is separate from the Assistant Checkout Mode above and
  works alongside any of those settings.
- **Checkout Display, shared with your IDEA89 dashboard.** A new field,
  Stores → Configuration → IDEA89 → Checkout Experience → **Checkout
  Display**, shown when Assistant Checkout Mode is Native checkout. **Full
  window** gives checkout the whole screen and hides the conversation behind
  it; **In the chat** keeps checkout inside the assistant panel alongside the
  conversation. Full window is the default and is how the assistant has
  behaved so far, so nothing changes on upgrade.

  This one setting lives in your IDEA89 account rather than in Magento, and
  the dashboard has the same field. Changing it in either place changes it in
  both, so the two screens cannot show you different answers. If IDEA89 cannot
  be reached when you save, nothing is changed and Magento tells you why,
  rather than storing a value your dashboard never learned about.

- **Pinned checkout bar.** A new admin toggle, Stores → Configuration →
  IDEA89 → Checkout Experience → **Pinned Checkout Bar**, defaulted ON. When
  on, the assistant shows a full-width bar above its message box reading
  "Checkout, N items, total" whenever the shopper's basket has items. It is new
  persistent chrome, so it can be switched off per store even though it only
  ever appears on a basket with something in it. Tapping it goes through the
  same Assistant Checkout Mode already configured above; it is a second,
  always-visible way to reach checkout, not a new checkout path.

## [1.2.1] - 2026-08-18

### Fixed
- Coupon expiry dates are now sent to IDEA89 as UTC timestamps with a `Z`
  suffix (`gmdate('Y-m-d\TH:i:s\Z', ...)`) instead of the server's local
  offset (`date('c')`, which yields e.g. `+01:00`). The API accepts only the
  `Z` form, so on any store whose PHP timezone was not UTC, every cart price
  rule that had an expiry date set was rejected on sync and the assistant
  never mentioned that promotion. Rules with no expiry date were unaffected.
  Affects both the save observer and the daily promo cron.

## [1.2.0] - 2026-07-01

### Added
- **Full product view (mini-PDP).** New `GET /idea89/product/mini?sku=|id=`
  controller (`Controller/Product/Mini.php` + the `idea89_product_mini`
  layout handle) renders the real Magento product-view page — media gallery,
  price, configurable swatches, and the add-to-cart form with its JS — with
  the site chrome (header/footer/breadcrumbs) stripped. The widget loads this
  in a contained popup, so add-to-cart and swatches behave exactly like the
  storefront PDP (same session, same form key, same AJAX). Gated on the module
  being enabled and on the same visibility rules as the storefront PDP
  (`canShow()` + website membership), so a product that is disabled, "Not
  Visible Individually", or not assigned to the current website cannot be
  rendered by guessing a SKU/id.
- **Shopper personalization (opt-in).** New admin section under
  Stores → Configuration → IDEA89 → **Personalization** (enable toggle + an
  encrypted Signing Secret shared with the IDEA89 dashboard). When enabled:
  - `GET /idea89/customer/me` mints a short-lived HMAC-signed identity token
    (customer id / group / login state) that the widget forwards as an opaque
    header. The browser cannot forge it, and the shopper's details never leave
    the storefront — IDEA89 reads identity without receiving PII.
  - `GET /idea89/products/live` (bearer-secret authed) returns live price and
    stock for a set of SKUs so the assistant can confirm current pricing
    server-to-server.

  Both endpoints are inert until Personalization is enabled and the secret is
  set, so existing installs are unaffected.

## [1.1.5] - 2026-06-07

### Added
- `sale_price` and `is_on_sale` in catalog sync payload, computed from
  Magento's `special_price` / `special_from_date` / `special_to_date`.
  Enables the IDEA89 `on_sale` shopper intent (e.g. "anything on sale?").

## [1.1.4] - 2026-06-07

### Added
- **Category names in catalog sync.** `ProductSerializer` now sends a
  `category_names: string[]` field alongside the existing `category_path`
  (which still carries the raw Magento category IDs joined by `" > "`).
  The API stores lowercase trimmed names in its `category_slugs` column
  and uses them for per-row category filtering — needed for queries like
  "cheapest in living" to actually narrow to the right category. Without
  this field the API's category filter is a silent no-op on Magento data
  because the legacy `split_part(category_path, '/', 2)` derivation
  expects slash-delimited names, not `>`-delimited IDs. Cached per
  process to avoid repeat lookups across the sync batch.

## [1.1.3] - 2026-05-30

### Added
- **PHP 8.5 support.** Adobe Commerce 2.4.9 (GA 2026-05-15) officially
  supports PHP 8.5 — composer.json `require.php` widened to
  `~8.1.0||~8.2.0||~8.3.0||~8.4.0||~8.5.0`. Module code uses only
  standard PHP 8.1+ syntax (typed properties, attributes, readonly,
  `declare(strict_types=1)`) and was confirmed compatible against the
  PHP 8.5 deprecation / removal list. The full Magento → PHP matrix is
  now: 2.4.6 → 8.1/8.2; 2.4.7 → 8.1/8.2/8.3; 2.4.8 → 8.2/8.3/8.4;
  2.4.9 → 8.4/8.5 (8.3 upgrade-only). README and MARKETPLACE.md updated.

### Changed
- README PHP badge `8.1–8.4` → `8.1–8.5`.

## [1.1.2] - 2026-05-30

### Added
- Always-visible IDEA89 brand strip above the General group in Stores >
  Configuration > IDEA89 > AI Shopping Assistant. Renders via a Fieldset
  override (`Block\Adminhtml\System\Config\AboutFieldset`) so there is no
  collapsible header, no group label, and no static-content-deploy step
  needed. Includes inline lightbulb SVG, tagline, version pill (auto-read
  from PackageInfo), and trust links to Documentation, Website, Support,
  and the merchant dashboard.
- Pulsing emerald glow + click-through on the lightbulb (opens
  https://idea89.com in a new tab). CSS-only animation, hover scales to
  1.08x and speeds up the pulse.

### Changed
- composer.json author email `hello@idea89.com` -> `support@idea89.com`
  so all merchant contact funnels through one inbox (matches SECURITY.md).
- composer.json author name `4K Technologies` -> `4K Technologies Ltd`,
  added `role: Developer` per Adobe Commerce Marketplace EQP guidance.
- composer.json `require.php` widened to PHP 8.1 | 8.2 | 8.3 | 8.4. Module
  code is forward-compatible across the whole range; the practical limit
  is whichever PHP version the host Magento install supports.
- composer.json `require.magento/*` moved off `*` to specific minor
  pins matching the 2.4.6 / 2.4.7 release vectors (EQP red-flags `*`).
- README PHP badge `8.2+` -> `8.1-8.4`; Requirements section now maps
  Magento version to its PHP range.

### Added (submission readiness)
- `MARKETPLACE.md` — internal submission brief covering the portal field
  map, EQP compliance status, MEQP pre-submission commands, and the
  outstanding human-action checklist.
- Copyright header on all 36 PHP files (4K Technologies Ltd, OSL-3.0).

### Removed
- Stale screenshot drift from working tree via repo-root `*.png`
  `.gitignore` rule (not user-visible, but cleans the dev experience).

## [1.0.0] - 2026-05-21

### Added
- AI shopping assistant widget (floating chat, mobile-responsive)
- Full product catalogue sync with variants, attributes, and stock
- Real-time sync via observers (product save, stock update, price rule save)
- Nightly full catalogue re-sync cron
- Minute-by-minute queue drain cron
- Promotion sync (active cart price rules)
- CMS page and category content sync
- Admin configuration panel (Stores > Configuration > IDEA89)
- Test Connection button in admin
- Sync Now button in admin
- Configurable widget position (bottom-left / bottom-right)
- Configurable brand colour
- Configurable assistant name and store context
- API URL override for self-hosted deployments
- CSP whitelist for IDEA89 API domain
- Encrypted API key storage
