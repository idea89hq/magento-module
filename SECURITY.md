# Security Policy

## Reporting a vulnerability

Please email **support@idea89.com** with the details (steps to reproduce, affected
version, impact). We aim to acknowledge within 2 business days and to ship a fix or
mitigation promptly. Please do not open a public issue for security reports.

## How this module is verified

Every change to this repository is automatically checked:

- **Magento Coding Standard** (`Magento2` ruleset via PHP_CodeSniffer) — runs on every push.
- **CodeQL** static analysis (JavaScript) — scans for security issues.
- **OpenSSF Scorecard** — supply-chain / security best-practice scoring.
- **Dependabot** — dependency monitoring and update PRs.

The module's only outbound network calls go to the merchant-configured IDEA89 API
through Magento's `Magento\Framework\HTTP\Client\Curl`. There is no obfuscated code,
no `eval`/`exec`/`base64`-decoded execution, and no third-party trackers bundled in
the module.

## Supported versions

The latest released version on Packagist (`idea89/magento2-assistant`) receives
security updates.
