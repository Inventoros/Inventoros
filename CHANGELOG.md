# Changelog

All notable changes to Inventoros are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

Full release notes, with the pull request behind each change, are on [GitHub Releases](https://github.com/Inventoros/Inventoros/releases).

## [Unreleased]

### Added

- Plugins: `product_updated`, `product_deleted` and the new `variant_created`, `variant_updated`, `variant_deleted`, `order_deleted`, `purchase_order_updated`, `purchase_order_deleted` and `stock_changed` actions fire from every surface (web, bulk actions, REST, GraphQL, MCP, imports and commands), once per record per transaction and only after it commits.
- Plugins: `plugin_licence($slug)` reports a paid plugin's runtime licence (`valid`, `expired`, `missing` or `unknown`) from entitlements the marketplace signs for each organization, refreshed daily in the background (`marketplace:refresh-entitlements`), verified with the marketplace public key, and honoured for 14 days past expiry while offline (`INVENTOROS_MARKETPLACE_ENTITLEMENT_GRACE_DAYS`).
- Plugins: `register_mcp_tool()` adds tools to the MCP server, named after the plugin slug and listed and callable only for users and tokens that hold the tool's permission.
- Plugins: page slots on customers, warehouses, stock audits, cycle counts, stock transfers, returns and work orders (`header`, `footer`, and `actions` on detail pages), an `actions` slot on the product, order and purchase order pages, and `header`, `sections` and `footer` on the settings hub. All are permission-gated on the server.
- Plugins: `register_webhook_event()` adds `{slug}.{event}` events to the webhook event picker, and `dispatch_webhook_event()` sends them after commit through the signed, retried webhook delivery.
- Plugins: new `category_created/_updated/_deleted`, `location_created/_updated/_deleted` and `warehouse_created/_updated/_deleted` actions, fired from every surface after commit.
- Plugins: a `stock_audit_completing` filter can refuse (or hold) the completion of a stock audit with a reason; the audit page shows it and the API answers 422 `completion_vetoed`.
- Plugins: menu items, submenu items and dashboard widgets take a `label_key` / `title_key` under `plugins.{slug}.`, shown in the user's language from the plugin's own messages, with the plain label as the fallback.
- Plugins: activation refuses a plugin whose pages or routes collide with an application route (same name, same method and URI, or inside a URI prefix the application uses) and says which route; plugin routes are namespaced `plg.{slug}.*` under `/p/{slug}/`.
- `OrderService::cancel()`, `restockForDeletion()` and `replaceItems()` take an optional `$actor` for the restock ledger rows, so queued jobs, commands and plugin syncs can cancel or edit orders without a signed-in user.
- `ReturnOrderService::receive()` takes an optional `$actor`: a queued job, command or plugin sync can receive a return without a signed-in user, and the restock ledger rows record that actor (else the signed-in user, else whoever approved the return).
- Plugins: a documented core PHP API ("Core PHP API" in the plugin guide): the `StockAdjustment`, order, return, shipment, stock audit, location stock, tracked stock, scan lookup and reorder methods plugins may call are tagged `@api` and guarded by a contract test, so they only gain trailing optional parameters between releases.
- Plugins: new `shipment_shipped` action, fired once after commit when a shipment first leaves the warehouse (marked shipped on any surface, or a carrier tracking update), and `shipment_cancelled`, fired after commit when a shipment is cancelled on its own or with its order.
- Organization memberships: a user can belong to several organizations, with a base role, custom roles and warehouse assignments in each, and works in one at a time. Members of two or more organizations get an organization switcher in the top bar; switching renews the session and CSRF token and opens the dashboard of the chosen organization. Every check (pages, REST, GraphQL, MCP, permissions, exports, notifications) follows the active organization. Users of one organization see no change. See `docs/plans/2026-10-05-multi-company-and-3pl-design.md`.
- API tokens work in the organization they were created in, whatever the browser later switches to, and stop working when that membership is withdrawn. `POST /api/v1/login` takes an optional `organization_id`, and `GET /api/v1/user` lists the user's `organizations`.
- Plugins: `OrganizationMembershipService` (`add()`, `changeRole()`, `remove()`, `createOrganization()`, `members()`, `organizationsFor()`) and `ActiveOrganization` (`userIn()`, `runAs()`, `authorize()`) join the core PHP API, with the new `organization_switched`, `organization_member_added` and `organization_member_removed` actions. See "Organizations and memberships" in the plugin guide.

### Changed

- Role assignments belong to one organization: a custom role, or the Administrator system role, held in one organization grants nothing in another. Existing assignments and API tokens belong to the user's organization after upgrading.
- A write sent from a browser tab still showing another organization (after switching in a second tab) is refused instead of being saved in the active organization.
- Changing the email or password of, or deleting, a user who also belongs to other organizations needs an administrator of each of them, and a delegated user manager may only manage users who hold no admin, manager or extra permission in any organization. Approval decisions, activity log entries and settings follow the organization the work happened in.
- A queued order or product import, and a scheduled report, run as the user in the organization they were started in, and are refused once that user is no longer a member of it.

- `supplier_created`, `supplier_updated`, `supplier_before_delete` and `supplier_deleted` fire from every surface (web, REST, GraphQL), after commit, instead of only from the web screens.
- A stock adjustment without a location keeps the location bins in step: a decrease drains the bins in fulfilment order and an increase lands in the product's primary location (before, the total moved and the bins did not). `StockAdjustment::adjust()` takes `syncBins: false` for callers that move the bins themselves.
- An order that names a warehouse draws its units from that warehouse's location bins first, then falls back to the other bins by warehouse priority; before, the bins drained by priority whatever the order's warehouse. `ProductLocationStockService::consume()` takes an optional preferred warehouse.
- A location-scoped stock audit counts the audited location only: it lists the products stocked there, snapshots what that location holds, and books each difference into it.
- `product_created`, `order_updated`, `purchase_order_created` and the customer hooks fire once per record per transaction, after commit; `order_updated` no longer fires while an order is being created, and `product_created` now runs after the product's options and variants are saved. The `product.updated` and `product.deleted` webhooks now fire for changes made through REST, GraphQL, imports and bulk actions too.
- Products saved as USD only because none was given (API, GraphQL, MCP and products import in 1.0.x) move to their organization's currency when upgrading, and new products without a currency take the organization's. See [UPGRADE.md](UPGRADE.md#currencies-and-money).
- Orders default to the organization's currency on every surface, and an order line without a unit price is priced in the order's currency or rejected when the product has no price in it.
- Money amounts accept at most two decimal places everywhere, and calculated amounts round half up to the cent instead of truncating.
- Outstanding Balances, dead stock, inventory valuation and category performance reports and the dashboard show totals per currency instead of adding currencies together; their exports carry a Currency column.

### Fixed

- Completing a stock audit lowered or raised on-hand stock without touching the location bins, so after a shortfall the locations claimed more than was on hand. Recount adjustments now move the bins with the total, and so do tracked-stock reconciliation and manual adjustments made without a location.
- Completing a stock audit outside a web request (a queued job or a plugin) failed because the recount adjustments had no user; they are attributed to the user completing the audit.
- Order restocks from a queued job or command failed for want of a signed-in user; they fall back to the order's creator.
- Receiving a return outside a web request (a queued job, command or plugin) failed because the restock ledger rows had no user.
- A plugin page was silently left out when any route, of any method, used its URI (for example a page under `/cycle-counts/` hidden by `PUT /cycle-counts/{id}`); only routes answering GET count now, and activation reports real conflicts.
- Order, return, transfer, purchase order and work order numbers kept repeating after the 9,999th of the day, so every further create failed until midnight.
- An order import on MySQL could fail with "Failed to allocate a unique sequence number" when another order took the same number at the same moment.
- A failing row in an import no longer aborts the rest of the file on PostgreSQL.

## [2.0.0]

Upgrading from 1.0.x: read [UPGRADE.md](UPGRADE.md) first. Every 1.0.x install must be upgraded by hand once: the in-app updater in 1.0.8 and earlier refuses the redirect GitHub uses for release downloads, so it cannot install any release. From 2.0.0 on, the updater works.

Before upgrading: take a database backup you have checked, run `php artisan optimize:clear` before migrating (stale 1.0.x caches fail with `Auth guard [customer] is not defined`), and on MySQL or PostgreSQL preview with `php artisan migrate --pretend --force` (without `--force` it waits at a production confirmation prompt). The 1.0.x backup silently leaves the database out when `mysqldump` or `pg_dump` is missing, so take your own dump.

### Breaking

- MCP tools are named in snake_case (`list_orders`) instead of the kebab-case class names (`list-orders-tool`). Client configurations and allow-lists that name tools must be updated.
- Published plugin UI assets moved from `public/plugins/{slug}` to `public/plugin-assets/{slug}`. cPanel installs must replace `public_html/index.php`.
- The API reference at `/docs/api` requires a signed-in user outside the `local` environment. Set `API_DOCS_PUBLIC=true` to make it public.
- `/api/v1` accepts bearer tokens only; browser sessions are no longer accepted there.
- Inventoros is licensed under AGPL-3.0-only. Releases up to and including 1.0.8 remain MIT.
- PHP 8.4.1 or newer is required (8.4 and 8.5 are supported). The 1.0.8 package already needed 8.4.1, so most hosts are unaffected. Building assets needs Node.js 20.19+ or 22.12+.
- The `schedule:run` cron entry is required. It also processes the database queue every minute, so shared hosting needs no separate worker; VPS and Docker installs should run one.
- Users with warehouse assignments on Member or custom roles are limited to those warehouses for locations, stock adjustments, audits, transfers, returns, purchase order receiving, shipments and the matching approvals and per-location reports, unless the role has the new `access_all_warehouses` permission. The order list, customers, purchase orders and other reports are not filtered by warehouse.
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

- Server-side validation, sign-in, password reset and pagination messages were always English. Laravel's messages now ship in `lang/` for all 14 languages, and the user's language (then the language cookie, then the default) applies to web and REST responses.
- Low-stock and out-of-stock alerts never fired for products sold by variant. A variant with its own minimum alerts when it crosses it; otherwise the product alerts when the sum of its active variants crosses the product's minimum. Low-stock alert emails and the `product.low_stock` webhook report that summed stock.
- A completed stock transfer showed "Transferred By: -". The transfer page shows who created it and who completed it; the new `stock_transfers.completed_by` column records the completer (REST `completed_by`), empty for transfers completed before upgrading.
- Users without a permission no longer see buttons and links that only lead to a 403 (new order, add product, the categories and locations tiles, edit and delete icons on orders, products, purchase orders, customers and suppliers, supplier links, new audit and more). Pages check the same permissions as the routes (`resources/js/lib/routePermissions.js`, kept in step by a test).
- The web order form had no currency choice. It has a Currency select (the organization's currency, or a chosen customer's), prefills line prices from the catalogue in that currency and shows totals in it.
- Sales Analysis added orders in different currencies together (revenue, average order value, the daily and status rows, outstanding and the exports). Its figures are in the organization's currency with the others listed beside them, top products carry their currency, and the exports have a Currency column with one line per currency.
- Products sold by variant showed 0 stock, "Out of Stock", a value of 0 and a low-stock alert while their variants held stock. Their stock is now the sum of their active variants' stock on the product list and page, the dashboard, the low stock, valuation, category and dead stock reports, reorder suggestions, the products export and the MCP `list_low_stock` tool; each variant is valued at its own price and cost.
- About 288 success and error flash messages that never reached the page.
- Sidebar items that were hidden from everyone, the discarded language cookie, and several routes that returned 500.
- Fresh installs on MySQL and PostgreSQL, and the web installer's database step.
- The web installer's database step migrating into a new `database/database.sqlite` instead of the MySQL or PostgreSQL database it was given (the shipped `.env.example` says `DB_CONNECTION=sqlite`), reporting success and then failing on the admin step with "Table 'users' doesn't exist". It now switches to the chosen connection before migrating and checks the tables exist there afterwards.
- A web-installed site stayed in debug mode (`APP_ENV=local`, `APP_DEBUG=true` from `.env.example`) and showed stack traces on errors. Finishing the installer sets `APP_ENV=production` and `APP_DEBUG=false`.
- The dashboard's Recent products showed Qty 0 for products sold by variant; it shows their summed variant stock.
- Sales Analysis and the dashboard's revenue this month counted cancelled orders, while the payment position and the analytics reports did not. Cancelled orders are left out of orders, revenue, items sold, average order value, top products, the daily trend and the period comparison; Sales by status still lists them.
- Validation messages named fields by their raw keys in every language ("customer name", "items.0.quantity") and ended with an English "(and 2 more errors)". The order, product and purchase order form fields are named in all 14 languages and the suffix is translated.
- The web installer's database step lifts PHP's time limit while migrating, and after an attempt that stopped part-way ("Table already exists") offers to reset the database and install again.
- The web installer failing with 419 over plain HTTP: `.env.example` no longer forces `SESSION_SECURE_COOKIE=true`; the installer sets it to `true` for HTTPS installs and `false` otherwise. Installer messages are shown in the user's language.
- `/install` and `/` answering 500 on a fresh install with the shipped `.env.example`: sessions and the cache use files until installation completes, since their database tables do not exist yet.
- Dates shown one day early for users west of UTC.
- Batch, serial and variant stock calls that returned 401 in the browser, and variant decrease adjustments that added stock.
- The in-app updater: it follows GitHub's download redirect (one allowlisted https hop at a time, size-capped), installs the cPanel package by its exact name instead of the first ZIP, puts `inventoros/` and `public_html/` in the right places (including `vendor/` and the web root), keeps a SQLite database in `database/`, runs cache and migration commands in a fresh PHP process, and rolls back files, `vendor/`, the web root and the database on failure.
- Plugin pages 404ing (or staying reachable after deactivation) when routes are cached, including every plugin page after `php artisan route:cache` or `optimize`: plugin main files now run once per application instead of once per PHP process, and may return a registration closure.
- Rolling back the purchase order variant migration on SQLite.
- Cancelling an order whose product or variant was deleted afterwards now puts the units back on that product instead of losing them.
- A rolled-back update reports "Update failed" once instead of "Update failed: Update failed and the previous version was restored".
- `php artisan db:seed` on a release install (no dev dependencies) no longer fails, and never creates the development test login in production. The cPanel package no longer ships the end-to-end test and screenshot seeders.

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
- Dependency updates clearing every known advisory in the shipped packages: laravel/framework 13.34.0 (debug page XSS, CVE-2026-102279), guzzlehttp/guzzle 7.15.5 and guzzlehttp/psr7 2.13.1 (noncanonical host bypass CVE-2026-69246, host confusion CVE-2026-59882, CRLF injection CVE-2026-55766, cookie scope, proxy and Referer issues), dompdf/dompdf 3.1.6 (SVG local file read CVE-2026-56722, chroot bypass CVE-2026-55554, file existence oracles, image DoS), league/commonmark 2.10.3 (DoS and XSS advisories) and league/flysystem 3.36.0. On the JavaScript side: axios 1.20.0, postcss 8.5.28, nanoid 3.3.19, brace-expansion 2.1.7, browserslist 4.29.3, shell-quote 1.9.0 and vite 7.3.6 with esbuild 0.28.2. `composer audit` and `npm audit --omit=dev` report nothing.
- The updater and the marketplace client refuse URLs whose host is not written in canonical form (userinfo, backslashes, percent-escapes, non-ASCII lookalikes, empty labels, trailing dots), so a host the HTTP client would read differently can no longer pass the download allowlist. Internationalised marketplace hosts must be configured in punycode.
- Names in Markdown mail (the account invitation) are shown as text: a user or organization name written as a Markdown link no longer becomes a link in the email.
- CI audits production Composer and npm dependencies on every change and weekly, and fails on high or critical advisories.
- Build-only npm packages are no longer listed as production dependencies. `tailwindcss-animate` (a Tailwind plugin) pulled Tailwind CSS 3 and its file watcher into the production tree, bringing braces (GHSA-vfj7-8cjw-p6xm, no patched release); it is now a dev dependency, and the libraries bundled into the app (Vue, Inertia, axios) are listed as dependencies. `npm audit --omit=dev` reports nothing. braces remains in the build toolchain only, where it reads the project's own fixed globs; it never ships, because releases contain the compiled assets and no `node_modules`.

## [1.0.x]

Releases 1.0.0 to 1.0.8 are described on [GitHub Releases](https://github.com/Inventoros/Inventoros/releases?q=v1.0).

[Unreleased]: https://github.com/Inventoros/Inventoros/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/Inventoros/Inventoros/compare/v1.0.8...v2.0.0
[1.0.x]: https://github.com/Inventoros/Inventoros/releases?q=v1.0
