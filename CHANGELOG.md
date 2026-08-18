# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
