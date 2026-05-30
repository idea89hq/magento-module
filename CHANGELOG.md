# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
