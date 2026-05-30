# Adobe Commerce Marketplace — Submission Brief

Internal reference for the Marketplace submission of `idea89/magento2-assistant`.
Kept inside the module so anyone preparing a release knows exactly what state
the codebase needs to be in. Not shipped to merchants (filtered out of the
Composer dist tarball via `.gitattributes` if needed for size — currently
left in because it documents what reviewers will check).

## Submission portal field map

| Portal field           | Value / source                                                                     |
| ---------------------- | ---------------------------------------------------------------------------------- |
| Extension name         | **IDEA89 — AI Shopping Assistant**                                                 |
| Package URN            | `idea89/magento2-assistant`                                                        |
| Version                | from `composer.json` `version` and `etc/module.xml` `setup_version` (must match)   |
| Edition                | **Magento Open Source 2.4.x · Adobe Commerce on prem 2.4.x · Adobe Commerce Cloud** |
| PHP compatibility      | 8.1 / 8.2 / 8.3 / 8.4 (from `composer.json require.php`) — module code is forward-compatible; the practical limit is whichever PHP version the host Magento install supports (2.4.6/2.4.7 = 8.1-8.3; 2.4.8+ = 8.3-8.4) |
| Category               | **Marketing → Customer Engagement** (primary) · **Sales → Conversion** (secondary) |
| Logo                   | `../branding/logo/png/idea89-icon-255.png` (Marketplace requires exactly 255×255)  |
| Banner (1200×300)      | not yet produced — generate from `branding/logo/idea89-logo-horizontal.svg`        |
| Screenshots (≥3)       | recommended: widget on PDP, widget on mobile, admin config, in-chat order tracking, store-locator card |
| License                | OSL-3.0 (see `LICENSE`)                                                            |
| Support URL            | https://idea89.com (or `support@idea89.com`)                                       |
| Privacy policy URL     | https://idea89.com/privacy                                                         |
| Documentation URL      | https://idea89.com/docs                                                            |
| Vendor                 | 4K Technologies Ltd                                                                |
| Vendor contact email   | `support@idea89.com`                                                               |

## EQP (Extension Quality Program) compliance status

Adobe runs every submission through a battery of automated and manual checks
under their Extension Quality Program. Status against each:

### Mandatory (Pre-Sale Pre-Approval)

| Check                                                              | Status         | Notes                                          |
| ------------------------------------------------------------------ | -------------- | ---------------------------------------------- |
| Composer name follows `vendor/package` convention                  | ✅              | `idea89/magento2-assistant`                    |
| Composer type is `magento2-module`                                 | ✅              | `composer.json` line 4                         |
| Module name matches Composer `name` field                          | ✅              | `Idea89_Assistant` ↔ `idea89/magento2-assistant` |
| Semantic version                                                   | ✅              | `1.1.1`; bump on every release                 |
| `etc/module.xml` `setup_version` matches `composer.json` `version` | ✅              | both `1.1.1`                                   |
| License declared + LICENSE file present                            | ✅              | OSL-3.0                                        |
| Marketplace-acceptable license (OSL/AFL/commercial)                | ✅              | OSL-3.0 is on the approved list                |
| `require.php` is constrained (no `*`)                              | ✅              | `~8.1.0\|\|~8.2.0\|\|~8.3.0`                     |
| `require.magento/*` are constrained (no `*`)                       | ✅              | minor version pins on each dep                 |
| Copyright header on every PHP file                                 | ✅              | all 36 files                                   |
| `LICENSE`, `README.md`, `composer.json`, `etc/module.xml` present  | ✅              | + CHANGELOG.md + SECURITY.md (bonus)            |
| `.gitattributes` excludes `.github/` from dist                     | ✅              | one-line export-ignore each                    |

### Coding standards (run before every release)

```bash
# In a Magento sandbox where this module is symlinked into app/code/Idea89/Assistant
composer require --dev magento/magento-coding-standard
vendor/bin/phpcs --standard=Magento2 \
  app/code/Idea89/Assistant
vendor/bin/phpcs --standard=MEQP1 \
  app/code/Idea89/Assistant
vendor/bin/phpcs --standard=MEQP2 \
  app/code/Idea89/Assistant
```

Maintained green by the `coding-standard.yml` GitHub Action (see badge in `README.md`).

### Security (Marketplace flags any failure)

| Check                              | Status | Notes                                            |
| ---------------------------------- | ------ | ------------------------------------------------ |
| No `eval()`, `system()`, `exec()`  | ✅      | grep on master at submission time                |
| All SQL via `Magento\Framework\DB` (no raw `mysql_*`) | ✅ | uses `select()->where(?, $val)` parameter binding |
| No hard-coded credentials          | ✅      | API key entered by merchant, stored as `obscure` `Encrypted` field |
| CSP headers respected              | ✅      | `etc/csp_whitelist.xml` configured                |
| Output escapes via `$escaper`      | ✅      | `.phtml` templates use `$block->escapeHtml(...)` |
| Frontend POSTs use CSRF tokens     | n/a    | no frontend POST endpoints in this module        |
| No PII leaving the merchant origin | ✅      | order tracking is Pattern A (browser ↔ Magento)  |
| `SECURITY.md` with disclosure email | ✅      | `support@idea89.com`                             |

### Module scope hygiene

| Check                                | Status | Notes                                          |
| ------------------------------------ | ------ | ---------------------------------------------- |
| No overriding of Magento core classes | ✅      | only observers + new blocks/controllers       |
| No layout XML modifying core handles in destructive ways | ✅ | adds head-link only |
| Sequence in `module.xml` declared    | ✅      | Catalog, Cms, Config, Csp, Store               |
| Frontend assets namespaced           | ✅      | `Idea89_Assistant::js/...`                     |
| Admin config tab uses unique ID      | ✅      | `<tab id="idea89">` + custom icon              |

### What still needs human action before clicking submit

- [ ] **Banner image (1200×300)**. Generate from `branding/logo/idea89-logo-horizontal.svg`. Adobe's portal validates the aspect; off-by-a-pixel rejects.
- [ ] **Screenshots ≥ 3, ≤ 6**. Take at 1440×900: widget on PDP, widget on mobile, admin config, in-chat order tracking, store-locator card. Optional sixth: dashboard view at `https://app.idea89.com`.
- [ ] **EULA document**. OSL-3.0 standard text is included as `LICENSE`; Adobe's portal requires also pasting it into the "Terms of Service" field of the listing.
- [ ] **Final QA install on a vanilla Magento 2.4.7 sandbox** via:
      ```
      composer require idea89/magento2-assistant:1.1.1
      bin/magento module:enable Idea89_Assistant
      bin/magento setup:upgrade
      bin/magento cache:flush
      ```
      Verify the admin tab loads with the new lightbulb icon and the test-connection button hits the API.

## Why this matters

Adobe Marketplace listings stay live indefinitely. Anything that ships under the
4K Technologies Ltd vendor account is associated with the brand on Marketplace.
Submission rejections are public on the listing's "Quality Report" page —
visible to merchants comparing extensions — so passing first time matters
beyond the dev convenience.

## After submission

- Marketplace review SLA: ~2–4 weeks for first review; resubmissions usually 1 week.
- When approved, the public listing URL becomes `https://commercemarketplace.adobe.com/idea89-magento2-assistant.html`.
- Update `README.md` and `composer.json` `support.docs` to point at the new
  Marketplace landing page in addition to `idea89.com`.

## Contact + maintenance

All marketplace-correspondence email goes to `support@idea89.com`. Adobe's
review team sometimes asks for a PHP unit test, a logo refresh, or a license
clarification — those land in the support inbox. Keep this file current with
the latest review's expectations so the next person preparing a submission
inherits the working knowledge.
