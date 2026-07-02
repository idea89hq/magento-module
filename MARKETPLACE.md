# Adobe Commerce Marketplace — Submission Brief

Internal reference for the Marketplace submission of `idea89/magento2-assistant`.
Kept inside the module so anyone preparing a release knows exactly what state
the codebase needs to be in. Not shipped to merchants (filtered out of the
Composer dist tarball via `.gitattributes` if needed for size — currently
left in because it documents what reviewers will check).

## Per-upgrade routine (RUN THIS ON EVERY MODULE UPGRADE)

Every time the module version is bumped, before publishing or submitting a new
version to the Marketplace, run the standing release gate from the repo root:

```bash
scripts/magento-release-check.sh
```

It runs the automated slice of the EQP checklist below — version consistency
(`composer.json` == `etc/module.xml` == `CHANGELOG` entry), no AI-authorship
footprint, security greps (no `eval`/`system`/`exec`/raw `mysql_*`/hardcoded
creds), copyright headers on every PHP file, "for Magento" trademark phrasing —
and **builds + validates the submission zip** (`composer archive`, then checks
it carries no `.gitignore`/`.gitattributes`/`.github`/build junk and that the
archived `module.xml` version matches). It changes and pushes nothing; it exits
non-zero on any failure and prints the remaining owner-only steps (publish +
Adobe portal upload). The zip lands at `/tmp/idea89-magento2-assistant-<ver>.zip`.

The upgrade sequence is therefore: **(1)** bump `composer.json` + `etc/module.xml`
+ add a `CHANGELOG` entry → **(2)** `scripts/magento-release-check.sh` (must pass)
→ **(3)** `scripts/publish-magento.sh "…" vX.Y.Z` (owner, idea89hq account) →
**(4)** upload the zip to the Adobe EQP portal as a new version (owner). The
manual sections below are the source of truth for what the script cannot check
(screenshots, banner, EULA paste, portal fields).

## Submission portal field map

| Portal field           | Value / source                                                                     |
| ---------------------- | ---------------------------------------------------------------------------------- |
| Extension name         | **AI Shopping Assistant for Magento 2** (post 2026-06-02 marketing-review rejection — Adobe flagged "IDEA89" in the title as a developer/company name. Vendor brand stays on the vendor record; title is descriptive + uses the "for Magento" trademark-policy phrasing) |
| Short description      | **An AI shopping assistant for Magento 2, connecting a storefront to the idea89 service for natural-language product discovery, in-chat order tracking, and a Pro-tier store locator.** (130-ish chars, uses "for Magento" phrasing, no bolded extension name) |
| Package URN            | `idea89/magento2-assistant`                                                        |
| Version                | from `composer.json` `version` and `etc/module.xml` `setup_version` (must match)   |
| Edition                | **Magento Open Source 2.4.x · Adobe Commerce on prem 2.4.x · Adobe Commerce Cloud** |
| PHP compatibility      | 8.1 / 8.2 / 8.3 / 8.4 / 8.5 (from `composer.json require.php`) — module code is forward-compatible; the practical limit is whichever PHP version the host Magento install supports (2.4.6 = 8.1-8.2; 2.4.7 = 8.1-8.3; 2.4.8 = 8.2-8.4; 2.4.9 = 8.4-8.5, plus 8.3 upgrade-only) |
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

## Marketing review — 2026-06-02 rejection + resubmission checklist

First submission (2026-05-30) cleared technical EQP but failed the **Marketing
review** with six items. Updated content lives in `dist/description.html` (v2
of the long description) and the table above (new title + short description).
Items resolved:

1. **Extension title** — was `IDEA89 AI Shopping Assistant`; Adobe read "IDEA89"
   as a developer/company name. New title: **AI Shopping Assistant for Magento 2**.
   Vendor brand still appears on the vendor record and inside the description
   body; it just leaves the listing title. Update this in the Adobe portal field
   directly — not in `description.html`.
2. **Long description first heading removed** — the old `<h2>idea89 — Your
   storefront, now fluent in shopper.</h2>` is gone. The new description opens
   straight into the introductory paragraph about the integrated service.
3. **No bolded extension name in body** — `<strong>idea89</strong>` style
   emphasis on the extension name has been stripped throughout. The one
   remaining bold-link to `idea89.com` is the integrated-service hyperlink that
   Adobe's integration template requires.
4. **"For Magento" trademark phrasing** — every reference rewritten:
   `"This extension for Magento 2"`, `"module for Magento"`, etc. Search for
   `"Magento extension"` / `"Magento module"` before resubmit; both should
   return zero hits.
5. **Integration template** — opening paragraph is now about the **idea89
   service**, with the first mention turned into a bold hyperlink to
   `idea89.com`. Second paragraph describes the extension/integration itself
   with an explicit use-case example. Followed by an **Account &amp; Pricing**
   `<h3>` block that states a separate account is required, sign-up link, free
   trial, paid plans, contact email — all per Adobe's spec.
6. **Features list** — moved up to sit immediately after `Account &amp; Pricing`,
   under a `<h3>Features</h3>` heading, as a single `<ul>` bullet list.

When resubmitting:

- Paste `dist/description.html` body (between the SELECT FROM / SELECT TO
  markers) into the Marketplace Long Description rich-text field.
- Replace the portal **Extension Name** field value (see table above).
- Replace the **Short Description** with the value in the table above.
- Keep the same logo, banner, screenshots, EULA, support links — only the
  marketing copy changed.

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

- [ ] **Build the submission zip with `composer archive`, NOT `zip -r`.** Adobe EQP rejects archives containing `.gitignore`, `.gitattributes`, or `.github/` ("Deprecated File found. Please remove …" — caught on the v1.1.5 submission). The `.gitattributes` in this module already has the right `export-ignore` rules for all three; `composer archive` honors them. Recipe from the public-repo clone at the tag:
      ```bash
      cd ~/Repos/magento-module-public
      git fetch --tags && git checkout vX.Y.Z
      composer archive --format=zip --dir=/tmp --file=idea89-magento2-assistant-X.Y.Z
      unzip -l /tmp/idea89-magento2-assistant-X.Y.Z.zip | grep -E '\.gitignore|\.gitattributes|/.github/' && echo "REJECT" || echo "OK"
      ```
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
