# Changelog

All notable changes to Inventoros are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

Full release notes, with the pull request behind each change, are on [GitHub Releases](https://github.com/Inventoros/Inventoros/releases).

## [Unreleased]

## [2.0.0]

Upgrading from 1.0.x: read [UPGRADE.md](UPGRADE.md) first. The in-app updater in 1.0.x cannot apply this release.

### Breaking

- MCP tools are named in snake_case (`list_orders`) instead of the kebab-case class names (`list-orders-tool`). Client configurations and allow-lists that name tools must be updated.
- Published plugin UI assets moved from `public/plugins/{slug}` to `public/plugin-assets/{slug}`. cPanel installs must replace `public_html/index.php`.
- The API reference at `/docs/api` requires a signed-in user outside the `local` environment. Set `API_DOCS_PUBLIC=true` to make it public.
- `/api/v1` accepts bearer tokens only; browser sessions are no longer accepted there.
- Inventoros is licensed under AGPL-3.0-only. Releases up to and including 1.0.8 remain MIT.
- PHP 8.4.1 or newer is required and PHP 8.5 is not supported yet. Building assets needs Node.js 20.19+ or 22.12+.

### Added

- Shipping and fulfilment: several shipments per order, EasyPost rates, labels, tracking and voids, manual shipments, and an optional shipment email to the customer.
- Customer portal at `/portal/{org-slug}` where invited customer contacts see orders, shipments, payments and invoices, and request returns.
- Approval workflows for purchase orders, stock adjustments and stock transfers, each off by default, with a Pending approvals page.
- Line and order discounts, payments and refunds, order payment status, and an Outstanding Balances (receivables aging) report.
- Reports: dead stock, inventory turnover, profit margin, sales by location and ABC analysis; CSV, Excel and PDF export for every report; scheduled report emails.
- Plugin marketplace: browse, install and update signed plugins from inventoros.com. Plugins can ship runtime UI (pages, dashboard widgets, menu items and tabs).
- Per-warehouse reorder points, warehouse and location capacity, fulfilment priority, and enforced warehouse access.
- Scheduled cycle counts, keyboard-wedge and camera scanning, more barcode symbologies and QR codes.
- Purchasing: send POs by email with the PDF, supplier links on products, supplier price history and ratings, quick reorder.
- Invoices with per-organisation numbers, emailed to the customer.
- Order, user and multi-currency product imports; import results row by row.
- Security events in the activity log, user activity alert emails, and Settings > API tokens.
- REST, GraphQL, MCP and webhook coverage for customers, returns, transfers, users, approvals, shipments and payments.
- Production Docker image on GHCR; PostgreSQL and SQLite backups in the updater.

### Fixed

- About 288 success and error flash messages that never reached the page.
- Sidebar items that were hidden from everyone, the discarded language cookie, and several routes that returned 500.
- Fresh installs on MySQL and PostgreSQL, and the web installer's database step.
- Dates shown one day early for users west of UTC.
- Batch, serial and variant stock calls that returned 401 in the browser, and variant decrease adjustments that added stock.

### Security

- Dashboard figures, GraphQL and MCP check the real permission for each screen and token.
- Stored mail and EasyPost secrets are never sent to the browser.
- XLSX exports cannot contain formulas.
- Marketplace packages are verified against a pinned Ed25519 key and a sha256, with SSRF and zip-slip protection.

## [1.0.x]

Releases 1.0.0 to 1.0.8 are described on [GitHub Releases](https://github.com/Inventoros/Inventoros/releases?q=v1.0).

[Unreleased]: https://github.com/Inventoros/Inventoros/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/Inventoros/Inventoros/compare/v1.0.8...v2.0.0
[1.0.x]: https://github.com/Inventoros/Inventoros/releases?q=v1.0
