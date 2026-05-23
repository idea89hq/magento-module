# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
