# Changelog

All notable changes to Inventoros are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

Full release notes, with the pull request behind each change, are on [GitHub Releases](https://github.com/Inventoros/Inventoros/releases).

## [Unreleased]

### Changed

- Products saved as USD only because none was given (API, GraphQL, MCP and products import in 1.0.x) move to their organization's currency when upgrading, and new products without a currency take the organization's. See [UPGRADE.md](UPGRADE.md#currencies-and-money).
- Orders default to the organization's currency on every surface, and an order line without a unit price is priced in the order's currency or rejected when the product has no price in it.
- Money amounts accept at most two decimal places everywhere, and calculated amounts round half up to the cent instead of truncating.
- Outstanding Balances, dead stock, inventory valuation and category performance reports and the dashboard show totals per currency instead of adding currencies together; their exports carry a Currency column.

### Fixed

- Order, return, transfer, purchase order and work order numbers kept repeating after the 9,999th of the day, so every further create failed until midnight.
- An order import on MySQL could fail with "Failed to allocate a unique sequence number" when another order took the same number at the same moment.
- A failing row in an import no longer aborts the rest of the file on PostgreSQL.

## [2.0.0]

Upgrading from 1.0.x: read [UPGRADE.md](UPGRADE.md) first. Every 1.0.x install must be upgraded by hand once: the in-app updater in 1.0.8 and earlier refuses the redirect GitHub uses for release downloads, so it cannot install any release. From 2.0.0 on, the updater works.

Before upgrading: take a database backup you have checked, run `php artisan optimize:clear` before migrating (stale 1.0.x caches fail with `Auth guard [customer] is not defined`), and on MySQL or PostgreSQL preview with `php artisan migrate --pretend`.

### Breaking

- MCP tools are named in snake_case (`list_orders`) instead of the kebab-case class names (`list-orders-tool`). Client configurations and allow-lists that name tools must be updated.
- Published plugin UI assets moved from `public/plugins/{slug}` to `public/plugin-assets/{slug}`. cPanel installs must replace `public_html/index.php`.
- The API reference at `/docs/api` requires a signed-in user outside the `local` environment. Set `API_DOCS_PUBLIC=true` to make it public.
- `/api/v1` accepts bearer tokens only; browser sessions are no longer accepted there.
- Inventoros is licensed under AGPL-3.0-only. Releases up to and including 1.0.8 remain MIT.
- PHP 8.4.1 or newer is required (8.4 and 8.5 are supported). The 1.0.8 package already needed 8.4.1, so most hosts are unaffected. Building assets needs Node.js 20.19+ or 22.12+.
- The `schedule:run` cron entry is required. It also processes the database queue every minute, so shared hosting needs no separate worker; VPS and Docker installs should run one.
- Users with warehouse assignments on Member or custom roles are limited to those warehouses unless the role has the new `access_all_warehouses` permission.
- Orders created before 2.0.0 show the payment status "Not tracked" and are left out of receivables, the portal balance and the invoice balance line; users with `record_payments` can mark them paid in bulk (Orders > Mark older orders paid).
- Purchase order, invoice and shipment emails show "Queued" until they are delivered. `sent_at`, `invoice_sent_at` and `customer_notified_at` are set on delivery, not when queued; the new `*_queued_at` columns record the queueing.

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
- The cPanel package carries a `release.json` manifest (version, layout, supported PHP range) that the updater checks before it takes the site down. `deploy/cpanel/build-release.php` builds and verifies the package in CI and locally.
- The scheduler works the database queue every minute on shared hosting (`QUEUE_RUN_VIA_SCHEDULER`), and the admin dashboard warns when the scheduler or queue is stuck.
- `APP_PUBLIC_PATH` gives command-line tasks the web root of a split (cPanel) install; the installer and updater set it.
- A plain "requires PHP 8.4.1" message from `index.php` and `artisan` on older PHP.

### Fixed

- A completed stock transfer showed "Transferred By: -". The transfer page shows who created it and who completed it; the new `stock_transfers.completed_by` column records the completer (REST `completed_by`), empty for transfers completed before upgrading.
- Users without a permission no longer see buttons and links that only lead to a 403 (new order, add product, the categories and locations tiles, edit and delete icons on orders, products, purchase orders, customers and suppliers, supplier links, new audit and more). Pages check the same permissions as the routes (`resources/js/lib/routePermissions.js`, kept in step by a test).
- The web order form had no currency choice. It has a Currency select (the organization's currency, or a chosen customer's), prefills line prices from the catalogue in that currency and shows totals in it.
- Sales Analysis added orders in different currencies together (revenue, average order value, the daily and status rows, outstanding and the exports). Its figures are in the organization's currency with the others listed beside them, top products carry their currency, and the exports have a Currency column with one line per currency.
- Products sold by variant showed 0 stock, "Out of Stock", a value of 0 and a low-stock alert while their variants held stock. Their stock is now the sum of their active variants' stock on the product list and page, the dashboard, the low stock, valuation, category and dead stock reports, reorder suggestions, the products export and the MCP `list_low_stock` tool; each variant is valued at its own price and cost.
- About 288 success and error flash messages that never reached the page.
- Sidebar items that were hidden from everyone, the discarded language cookie, and several routes that returned 500.
- Fresh installs on MySQL and PostgreSQL, and the web installer's database step.
- The web installer's database step lifts PHP's time limit while migrating, and after an attempt that stopped part-way ("Table already exists") offers to reset the database and install again.
- `/install` and `/` answering 500 on a fresh install with the shipped `.env.example`: sessions and the cache use files until installation completes, since their database tables do not exist yet.
- Dates shown one day early for users west of UTC.
- Batch, serial and variant stock calls that returned 401 in the browser, and variant decrease adjustments that added stock.
- The in-app updater: it follows GitHub's download redirect (one allowlisted https hop at a time, size-capped), installs the cPanel package by its exact name instead of the first ZIP, puts `inventoros/` and `public_html/` in the right places (including `vendor/` and the web root), keeps a SQLite database in `database/`, runs cache and migration commands in a fresh PHP process, and rolls back files, `vendor/`, the web root and the database on failure.
- Plugin pages 404ing (or staying reachable after deactivation) when routes are cached, including every plugin page after `php artisan route:cache` or `optimize`: plugin main files now run once per application instead of once per PHP process, and may return a registration closure.
- Rolling back the purchase order variant migration on SQLite.

### Security

- Dashboard figures, GraphQL and MCP check the real permission for each screen and token.
- Stored mail and EasyPost secrets are never sent to the browser.
- XLSX exports cannot contain formulas.
- Marketplace packages are verified against a pinned Ed25519 key and a sha256, with SSRF and zip-slip protection.
- The example plugin ZIP attached to releases is no longer signed with the core update key, so it can never pass the updater's signature check. The release package is checked for `.env` files, tests, dev dependencies and other files that must not ship.
- A user administrator who is not an admin can no longer edit, reset the password of, or delete an admin, a manager, or anyone holding permissions they lack.
- Each organization's mail goes through its own mailer, so a queue worker never sends one organization's mail through another's SMTP. Password-reset and invitation mail always uses the instance mailer (`MAIL_*` in `.env`).
- API tokens: `POST /api/v1/tokens` never mints a token broader than the calling token and the user's permissions. GraphQL approvals and every approval listing honour token abilities.
- Scheduled reports only go to users who may view the report's data. Report files are no longer stored in the queue tables.
- Emailing purchase orders and invoices is rate limited per user and per organization (`DOCUMENT_EMAILS_*`); over the limit the API returns 429 `rate_limited`.
- Order creators cannot approve their own orders unless they are an admin and admins may self-approve (422 `self_approval`).
- Export downloads are limited to the user who requested them and admins. A portal password change signs the contact out of their other portal sessions. The portal invoice no longer shows payment references.
- Purchase order totals are recomputed on every edit, so a shipping- or tax-only REST or GraphQL edit can no longer push a PO past the approval threshold without approval.
- Marketplace signatures cover each package's slug, version and sha256, `plugin.json` must declare the signed version, and updates must be newer, so an older signed package cannot be replayed as an update. The marketplace only updates plugins it installed.
- Plugins are administered by one organization per installation (`INVENTOROS_PLUGIN_ADMIN_ORG`, default the first organization); other organizations' admins can browse plugins only.
- Plugin pages always require sign-in, cannot claim reserved or existing URIs, and plugin links must be http(s).
- Marketplace downloads are streamed to disk and stopped at the size cap.
- Laravel Excel 4.0.3 and PhpSpreadsheet 5.10.0 (CVE-2026-59933, CVE-2026-59932, CVE-2026-59931, CVE-2026-84374). Imports pick their reader from the detected file type.
- Shipment creation checks access to the ship-from warehouse.
- Creating, editing and deleting warehouses in the web app needs `create_warehouses`, `edit_warehouses` and `delete_warehouses`; `view_warehouses` alone used to allow all three.
- Database errors are no longer shown to users. Receiving a purchase order, stock audits, transfers, work orders, order and return actions, imports and the REST and GraphQL equivalents used to put the raw SQL error in the message; they now show the standard error page (HTTP 500) and the error is logged. Business rule refusals (wrong status, not enough stock) are still shown as before.

## [1.0.x]

Releases 1.0.0 to 1.0.8 are described on [GitHub Releases](https://github.com/Inventoros/Inventoros/releases?q=v1.0).

[Unreleased]: https://github.com/Inventoros/Inventoros/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/Inventoros/Inventoros/compare/v1.0.8...v2.0.0
[1.0.x]: https://github.com/Inventoros/Inventoros/releases?q=v1.0
