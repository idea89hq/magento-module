# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.0] - 2026-10-07

### Added
- **The in-chat checkout starts on the right country.** The checkout
  context now sends `default_country`: the quote's shipping country when it
  has one (a signed-in customer's address, or a country chosen in the
  cart's estimator), else the store's default country (Stores >
  Configuration > General > Country Options). The assistant's delivery form
  now lists countries by name and starts on it, instead of asking shoppers
  for a two-letter code.

### Changed
- The Personalization help text now says where the Signing Secret comes
  from: create it in the IDEA89 dashboard (Settings → Widget →
  Personalization), which can now generate it.
- **Store details sync sends facts only:** the store name, display currency,
  general contact email (not when it is still Magento's placeholder
  `owner@example.com`) and website. It no longer sends free text or a
  generated "An online store selling products at …" sentence.

### Removed
- **The Brand Colour field** (Stores > Configuration > IDEA89 > Widget
  Appearance). Set the colour in the IDEA89 dashboard instead, under
  Settings > Widget, with the theme, fonts and a live preview. The module
  printed this colour on the storefront and it silently beat the dashboard,
  so the dashboard's picker, preview and contrast warning showed one colour
  while shoppers saw another. After upgrading, an hourly cron job sends a
  colour you had set here to IDEA89 once and then deletes it from Magento's
  config. IDEA89 uses it only if the dashboard is still on the theme's own
  palette, so shoppers keep seeing the same colour. The store locator page
  also follows the dashboard colour now.
- **The Store Context field** (Stores > Configuration > IDEA89 > General).
  Describe your store in the IDEA89 dashboard instead, under Settings >
  AI & Knowledge > Store context. The two fields were separate: both were
  given to the assistant on every chat, neither screen showed the other, and
  they could contradict each other. Text you entered here before this
  release has been copied into the dashboard field if that was empty, so
  nothing is lost.

## [1.4.0] - 2026-10-06

### Added
- **Product attributes with labels, types and settings.** Every attribute
  that is visible on the storefront, searchable or used in layered
  navigation is sent with its store-view label, input type, option labels
  and those settings, so the assistant can filter on them and confirm a
  shopper's requirement ("vegan", "under 120 cm wide") from them. Each
  configurable child sends its own values where they differ from its
  parent's, and its own stock quantity. Previously only searchable or
  filterable attributes were sent, as codes with no labels.
- **Tier prices, short description, category paths and tax basis.** Tier
  prices for all customer groups or not-logged-in shoppers, the short
  description, each category as its path of names, whether catalogue prices
  include tax (Stores → Configuration → Sales → Tax → Calculation Settings →
  Catalog Prices) and the product's tax rate at the store's default
  destination.
- **Deleted, disabled and hidden products leave the assistant.** A deleted
  product is removed within a minute; a product saved as disabled or not
  visible in the catalogue or search is removed instead of synced; the
  nightly sync removes any that changed by mass action or import.
- **On-demand product data.** `POST /idea89/products/live` also answers
  `{"sku": "...", "full": true}` with one product's full data and
  `{"search": "...", "limit": 5}` with up to ten matching products, in the
  sync's format. The existing `{"skus": [...]}` request is unchanged.

### Fixed
- **A saved product now syncs within a minute.** The save queue was written
  to `core_config_data` and read back through the config cache, which the
  write never cleared, so a saved product waited for a cache flush or the
  nightly sync. The queue is now in the flag table, read from the database;
  ids an older version left at the old path are picked up once.
- **Descriptions keep their line breaks.** Paragraphs and list items were
  joined ("…with it.100% linen, 250 gsm50cm"); each block is now a line.
- **Stock saves send the quantity after the save.** With multi-source
  inventory the salable quantity read inside a stock-item save was the
  previous one; the product is now queued and its salable quantity sent by
  the drain cron within a minute (no HTTP call during the save).

- **Test Connection says when the API cannot be reached.** A refused or
  timed-out connection reached the admin as Magento's error page, and the
  button showed "Unexpected non-whitespace character after JSON"; it now
  shows the API address and the connection error.

- **An unreachable API no longer breaks admin saves or stops a sync part-way.**
  A refused connection, DNS failure or timeout threw out of every catalogue
  write: saving a cart price rule failed with an error, and a cron stopped at
  the first product. The write is now logged and counted as failed (the
  nightly sync catches it up), and Sync Now says the API could not be reached
  instead of reporting success.
- **Changes the API could not take are retried the next minute.** When the
  API is unreachable, rate-limited (408, 429) or erroring (5xx), the queued
  saves, deletions and stock updates go back on the queue instead of waiting
  for the nightly sync. After the first such failure the rest of the run is
  queued again without being sent, so a down API costs one attempt a minute.
  A product the API rejects (other 4xx) is not retried; the nightly sync
  still covers it.
- **Sync Now reports what it did.** It showed "Synced completed products"; it
  now shows the products synced, hidden or disabled ones removed and any that
  failed. A full sync that cannot reach the API stops at the first batch and
  no longer records a "last synced" time.

- **Configurable prices are sent as entered.** A configurable's own final
  price is the storefront display amount, with tax added when prices are
  shown including tax, while `price_includes_tax` said ex-tax. Its price is
  now its cheapest child's that can be bought (rule prices applied).
- **Only CMS pages shoppers can open are synced.** An active page assigned to
  no store view (not on the storefront) was sent, and the assistant answered
  from it. Pages are now those of the synced store view or all views, and
  each content batch carries the ids of every page synced, so the API
  withdraws pages that were unassigned, disabled or deleted (older APIs
  ignore the list).
- **Variant options carry their names.** Each variant's `option_labels`
  gives the label shoppers see for each option code (the configurable
  option's label, else the attribute's store label), so an option such as
  "Heat and Massage Option" is not known to the assistant only by its code.
- **Descriptions from Page Builder's HTML Code element are sent as text.**
  That element stores its markup escaped, so tags arrived as text ("<P>...").
- **The live endpoint's `skus` price matches the sync.** It returned a
  configurable's display amount (with tax when prices are shown including
  it), which the API then put in place of the synced price.
- **Decimal attribute values are sent without the stored trailing zeros**
  ("53.3", not "53.300000"); the raw value is unchanged.

### Added
- **Excluded Attributes** (Stores > Configuration > IDEA89 > Content Sync):
  product attributes not to send, for display and admin settings a store has
  marked visible on the storefront ("Hide Inc. VAT Price", panel colours,
  search weighting). The list holds every attribute the sync sends; ones whose
  code looks like a setting are listed first and marked, none is excluded
  until selected. Excluded codes are left out for products, their variants
  and the schema-1 map; run Sync Now after changing it.

### Changed
- **CMS page sync is on by default for new installs.** An existing install
  that never saved the setting keeps it off (written explicitly on upgrade).
- **Prices include a catalogue price rule for logged-out shoppers.** The
  sync runs from cron, where Magento does not apply catalogue price rules to
  the final price; the rule price is now read from the rule index.
- **Stock after an order on multi-source inventory stores.** An order or a
  cancellation now pushes the ordered products' salable quantity within a
  minute. With MSI an order only places a reservation, so the stock-item
  observer never fired and the quantity waited for the nightly run.
- **Store view.** Products are read in the default store view (previously
  the admin store), so labels and option labels match the storefront.
- **The widget's design fonts are allowed by the content security policy.**
  The Concierge and Aurora designs now load their fonts from the IDEA89 API
  into the page itself, so the module adds the API host to `style-src` and
  `font-src` alongside the existing `script-src`, `connect-src` and
  `img-src` entries. Without it, a store that enforces its CSP shows the
  fallback fonts; nothing else is affected.

### Upgrade notes
- Run `bin/magento setup:upgrade` (a data patch keeps your CMS sync
  setting) and, in production mode, `bin/magento setup:di:compile`.
- The module now declares the core modules it already used
  (CatalogInventory, CatalogRule, ConfigurableProduct, Customer, Eav, Tax).
- The first full sync after upgrading re-sends every product with the new
  fields.

## [1.3.2] - 2026-10-01

### Changed
- **The catalogue sync key is now part of setup.** Stores created in IDEA89
  from 1 October 2026 need it before their catalogue will sync, so the field
  is no longer marked optional. Create it in your IDEA89 dashboard under
  API & Domains and paste it into Stores → Configuration → IDEA89 → General
  → Catalogue Sync Key.

### Fixed
- **Sync Now says why a sync was refused instead of reporting success.** When
  IDEA89 turns a sync away because the sync key is missing or out of date,
  Sync Now shows IDEA89's explanation, the full sync stops after the first
  refused batch rather than sending every page, and "last synced" is not
  updated. Previously the sync reported "completed" and the catalogue stayed
  empty.
- **Test Connection checks the sync key too.** It used to check only that
  IDEA89 could be reached. It now asks IDEA89 whether a catalogue sync from
  this store would be accepted, with nothing written, and reports a missing
  or replaced sync key straight away.

### Upgrade notes
- Run `bin/magento setup:upgrade` and, in production mode,
  `bin/magento setup:di:compile` (the Sync Now action has a new dependency).

## [1.3.1] - 2026-10-01

### Added
- **Catalogue sync key.** New optional field, Stores → Configuration →
  IDEA89 → General → Catalogue sync key (stored encrypted). Create the key
  in your IDEA89 dashboard under API & Domains and paste it here. Once a
  sync arrives with the key, IDEA89 accepts catalogue, price, offer and FAQ
  updates for your store only when they carry it. Your API key is visible
  in your storefront's page source; the sync key never leaves your server,
  so nobody else can change what the assistant knows about your products.
  Leaving the field empty keeps syncing exactly as before.

### Security
- **Guest order lookup can no longer be used to guess orders.** A
  successful lookup no longer resets the per-visitor attempt limit (10 per
  hour), so someone holding one real order number cannot keep guessing
  others. The visitor's address now comes from the connection itself, not
  from a forwarded header a caller can set. Emails are compared in constant
  time, and malformed requests get the same "order not found" reply as a
  wrong order number or email.

### Upgrade notes
- Run `bin/magento setup:upgrade` and, in production mode,
  `bin/magento setup:di:compile` (the order lookup has a new dependency).

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
