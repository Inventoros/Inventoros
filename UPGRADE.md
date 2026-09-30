# Upgrade Guide

## Upgrading from v1.0.x to v2.0.0

v2.0.0 is a major release: MCP tool names, the plugin asset path, access to `/docs/api` and the license all change. Read this whole section before you start. The full list of changes is in [CHANGELOG.md](CHANGELOG.md).

> **Every 1.0.x install must be upgraded by hand this time.** The in-app updater in 1.0.8 and earlier cannot install any release: GitHub serves release downloads through a redirect, and the 1.0.x updater refuses redirects. Follow the [upgrade steps](#upgrade-steps) below. From 2.0.0 on, the in-app updater (Admin > Update) installs later releases again.

### Contents

- [Requirements](#requirements)
- [Upgrade steps](#upgrade-steps)
- [Scheduler and queue worker](#scheduler-and-queue-worker)
- [New environment variables](#new-environment-variables)
- [New permissions](#new-permissions)
- [MCP tool names](#mcp-tool-names)
- [Plugin assets](#plugin-assets)
- [API reference access](#api-reference-access)
- [Plugin marketplace](#plugin-marketplace)
- [Currencies and money](#currencies-and-money)
- [Other behaviour changes](#other-behaviour-changes)
- [License](#license)

### Requirements

- **PHP 8.4.1 or newer** (8.4 and 8.5). The 1.0.8 release package already needed PHP 8.4.1, so most 1.0.8 installs meet this.
- **Node.js 20.19+ or 22.12+**, only if you build the frontend yourself. The cPanel release package and the Docker image ship pre-built assets.
- MySQL 8.0+ or PostgreSQL 13+ (SQLite for development), as before.

### Upgrade steps

**Do not use the in-app updater (Admin > Update) to move from 1.0.x to 2.0.0.** It cannot install any release. Upgrade by hand as described below.

Before you start, on every install:

- **Take a database backup you have checked.** Restore it somewhere, or at least open the dump and confirm it contains your tables. `php artisan app:update --backup` makes a files-and-database backup; keep your host's own backup as well.
- **On MySQL or PostgreSQL, preview the migrations first** with `php artisan migrate --pretend` (run it after replacing the files, before migrating). Installs that were patched by hand, or that have been upgraded many times, can have a schema that differs from a fresh one. If the preview shows something that will fail, stop and restore rather than migrate halfway.
- **Clear the old caches before migrating.** Cached 1.0.x config and routes do not know about 2.0.0's customer portal guard, so the app fails with `Auth guard [customer] is not defined` until `php artisan optimize:clear` runs.

#### cPanel (release package)

1. **Back up** the database and the files (see above). Keep a copy of `.env`.
2. **Put the site in maintenance mode:** `php artisan down` from the application folder (the one that contains `artisan`).
3. **Download** `inventoros-cpanel-2.0.0.zip` from [the v2.0.0 release](https://github.com/Inventoros/Inventoros/releases/tag/v2.0.0) and extract it on your computer.
4. **Replace the application folder.** Rename the current folder (for example `~/inventoros` to `~/inventoros-1.0`), upload the new `inventoros/` folder in its place, then copy these across from the old folder:
   - `.env`
   - `storage/` (uploaded files, logs, backups)
   - any plugins you installed yourself under `plugins/` (the bundled `hello-world` plugin is in the new package)

   Then add `APP_PUBLIC_PATH=../public_html` to `.env` (adjust if your web root is elsewhere). Command-line tasks such as plugin activation and `php artisan update` use it to write to the web root the site serves.
5. **Replace the web root files.** From the package's `public_html/`, upload `index.php` and the `build/` folder over the ones in your `public_html/` (replace `build/` completely). `index.php` must be replaced: the 2.0.0 version calls `usePublicPath()` so plugin assets resolve. Keep `public_html/storage` and, if it exists, `public_html/plugin-assets/`. If your application folder is not `../inventoros`, edit `$laravelPath` in the new `index.php` as you did before.
6. **Clear caches, migrate, and rebuild caches:**

   ```bash
   cd ~/inventoros
   php artisan optimize:clear
   php artisan migrate --pretend    # MySQL / PostgreSQL: review the SQL first
   php artisan migrate --force
   php artisan optimize
   php artisan up
   ```

7. **Add the scheduler cron entry** in [Scheduler and queue worker](#scheduler-and-queue-worker) if you do not have it. It is required.
8. **Review warehouse access and grant the new permissions** your roles need (see [New permissions](#new-permissions)).

When everything works, delete the old folder.

**Later updates (2.0.0 onwards).** Admin > Update installs new releases. It downloads `inventoros-cpanel-<version>.zip` and its signature, checks the package's `release.json` (version, package layout, supported PHP range) before touching anything, backs up the files (including `vendor/` and the web root) and the database, then installs `inventoros/` into the application folder and `public_html/` into the web root. Code folders (`app`, `config`, `database`, `resources`, `routes`, `vendor`, ...) are replaced whole, so files removed from a release disappear. Never touched: `.env`, `storage/`, `plugins/`, `bootstrap/cache`, a SQLite database in `database/`, `public_html/plugin-assets/`, the storage link, and an existing `.htaccess`, `.user.ini`, `web.config` or `robots.txt`. Other files in the web root are left in place. `index.php` is rewritten to point at your application folder. Caches are cleared before migrating and rebuilt after, in a separate `php artisan` process; if any step fails, the backup (files and database) is restored.

#### VPS (git checkout)

```bash
cd /var/www/inventoros
php artisan app:update --backup    # and your own database dump
php artisan down
git fetch --tags
git checkout v2.0.0
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize:clear
php artisan migrate --pretend    # MySQL / PostgreSQL: review the SQL first
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan up
```

Then set up the scheduler and queue worker below if they are not already running.

#### Docker

Pull the `2.0.0` image and recreate the containers. With `RUN_MIGRATIONS=true` on the `app` service, migrations run on start. See [installation-docker.md](docs/site/sections/installation-docker.md#updating). Back up the database volume first.

#### Database changes

This release adds many migrations. Existing `order_items` rows get an estimated `unit_cost` (the product's current cost), which reports flag as estimated. Existing copies of the system roles and permission set templates receive the new permissions listed below.

**Payment status on older orders.** Payments were not recorded before 2.0.0, so orders created before the upgrade show the payment status **Not tracked** rather than Unpaid. They are left out of the Outstanding Balances report, the dashboard receivables figure, the customer portal balance and the invoice balance line. Recording a payment on one starts tracking it. If those orders were paid, a user with `record_payments` can use **Mark older orders paid** on the Orders page: every Not tracked, non-cancelled order placed before the chosen date gets a payment of method Other for its full total, dated on the order date, with the reference "Marked paid (pre-tracking)", and the change is logged.

### Scheduler and queue worker

**The scheduler cron entry is required.** Without it, queued email is never sent and cycle counts, scheduled reports, shipment tracking and reorder checks never run. Add one entry that runs every minute:

```text
* * * * * cd /path/to/inventoros && php artisan schedule:run >> /dev/null 2>&1
```

It runs `inventory:run-cycle-counts` (hourly), `reports:send-scheduled` (every 15 minutes), `shipping:track` (every 30 minutes), `inventory:check-reorder-points` (daily) and the nightly `activity-logs:prune` and `webhooks:prune`.

**The queue.** Purchase order and invoice emails, scheduled reports, approval, shipment and user activity alerts, webhook deliveries and import jobs go through the database queue. A purchase order, invoice or shipment email shows **Queued** until the mail is actually delivered, then Sent.

The admin dashboard warns when the scheduler has not run for 10 minutes, when queued jobs have waited more than 15 minutes, or when jobs failed in the last 24 hours.

- **cPanel and other shared hosting:** with `QUEUE_CONNECTION=database` the scheduler also works through the queue every minute (`queue:work --stop-when-empty --max-time=50`), so the `schedule:run` entry above is all you need. No separate worker is required. `QUEUE_RUN_VIA_SCHEDULER` turns this off or on.
- **VPS:** run a dedicated worker so jobs are processed as soon as they are queued. Install the systemd units in `deploy/systemd/` (`inventoros-worker.service`, plus `inventoros-scheduler.timer` if you prefer it to cron). See [installation-vps.md](docs/site/sections/installation-vps.md#scheduler-and-queue-worker). Run `php artisan queue:restart` after each deploy.
- **Docker:** `docker-compose.prod.yml` already runs `worker` and `scheduler` containers (and sets `QUEUE_RUN_VIA_SCHEDULER=false`).

### New environment variables

All are optional. `.env.example` lists each one with its default.

| Variable | Default | Purpose |
|---|---|---|
| `API_DOCS_PUBLIC` | `false` | Make `/docs/api` public outside `local`. |
| `APP_PUBLIC_PATH` | unset | Web root of a split (cPanel) install, e.g. `../public_html`. Set by the installer and the updater. |
| `QUEUE_RUN_VIA_SCHEDULER` | `true` with the database queue | Let `schedule:run` work the queue every minute. Set `false` when a dedicated worker runs. |
| `INVENTOROS_MARKETPLACE_URL` | `https://inventoros.com` | Marketplace the app installs plugins from. |
| `INVENTOROS_MARKETPLACE_PUBLIC_KEY` | see [Plugin marketplace](#plugin-marketplace) | Ed25519 public key marketplace packages are verified against. |
| `INVENTOROS_MARKETPLACE_CACHE_SECONDS` | `300` | How long the catalog is cached. |
| `INVENTOROS_MARKETPLACE_TIMEOUT` | `20` | Marketplace request timeout, in seconds. |
| `INVENTOROS_MARKETPLACE_MAX_DOWNLOAD_BYTES` | 50 MB | Largest plugin package the app downloads. |
| `INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP` | `false` | Let the updater continue when no database backup method works. |
| `INVENTOROS_UPDATE_MAX_ENTRIES`, `INVENTOROS_UPDATE_MAX_BYTES` | 50000, 300 MB | Update archive limits. |
| `INVENTOROS_UPDATE_HOSTS` | GitHub's download hosts | Hosts the updater follows download redirects to. |
| `INVENTOROS_UPDATE_MAX_REDIRECTS`, `INVENTOROS_UPDATE_MAX_DOWNLOAD_BYTES` | 5, 200 MB | Download redirect and size limits. |
| `INVENTOROS_RESTORE_MAX_ENTRIES`, `INVENTOROS_RESTORE_MAX_BYTES` | 500000, 4 GB | Limits when restoring a pre-update backup. |
| `INVENTOROS_UPDATE_PHP_BINARY` | found automatically | PHP CLI binary the updater runs `php artisan` with. |
| `INVENTOROS_UPDATE_ARTISAN_SUBPROCESS`, `INVENTOROS_UPDATE_ARTISAN_TIMEOUT` | `true`, 900 | Run post-update commands in a separate process, and its timeout in seconds. |
| `INVENTOROS_PLUGIN_MAX_ENTRIES`, `INVENTOROS_PLUGIN_MAX_BYTES` | 2000, 50 MB | Plugin package limits. |
| `INVENTOROS_PLUGIN_ADMIN_ORG` | first organization | The one organization whose admins may install, update and remove plugins. See [Plugin marketplace](#plugin-marketplace). |
| `LOW_STOCK_ALERT_COOLDOWN_MINUTES` | `1440` | Minimum gap between low-stock alerts for one product. |
| `REPORTS_MAX_ROWS` | `10000` | Row cap for any report. |
| `REPORTS_PDF_MAX_ROWS` | `1000` | Row cap for report PDFs; CSV and Excel carry the full set. |
| `DOCUMENT_EMAILS_PER_USER_PER_MINUTE` | `10` | Purchase order and invoice emails one user may send per minute. |
| `DOCUMENT_EMAILS_PER_ORGANIZATION_PER_DAY` | `500` | Purchase order and invoice emails one organization may send per day. |
| `RUN_MIGRATIONS` | `false` | Docker image only: migrate on container start. |

EasyPost API keys are entered in Settings > Shipping and stored encrypted, not in `.env`.

### New permissions

Administrators hold every permission. The upgrade migrations grant the new permissions to the built-in roles and permission set templates as shown; **custom roles get nothing automatically**, so grant what they need in Roles.

| Permission | Built-in roles | Permission set templates |
|---|---|---|
| `view_shipments`, `create_shipments` | Administrator, Manager | Order Processor and Warehouse Staff get both; Read-Only Auditor gets view |
| `view_payments`, `record_payments` | Manager | Order Processor gets both; Read-Only Auditor and Reports Viewer get view |
| `access_all_warehouses` | Manager | Inventory Manager, Read-Only Auditor |
| `approve_purchase_orders`, `approve_stock_adjustments`, `approve_stock_transfers` | Administrator, Manager | New "Approver" template (added when your install has the default templates) |

Things to check after upgrading:

- **Warehouse access is enforced.** A user who has warehouse assignments and whose role lacks `access_all_warehouses` now sees and acts on only the assigned warehouses. The Manager role gets the permission on upgrade, but **Member and custom roles do not**, so staff on those roles who were assigned warehouses (for example just to set the header switcher) lose sight of the others. To keep a role organisation-wide, open Roles, edit the role and tick "Access All Warehouses", or remove the user's warehouse assignments. Users with no assignments are unaffected unless the organization setting "Restrict users to their assigned warehouses" is on.
- **Payments are hidden without `view_payments`.** Order payloads omit payment fields unless both the role and the API token allow it.
- **Approvals are off by default.** Turn them on per organization; make sure someone holds the matching `approve_*` permission first.
- **The dashboard** hides figures the user's permissions do not cover.

### MCP tool names

MCP tools used to be exposed under kebab-case names derived from their class names. They now have explicit snake_case names. Update any MCP client configuration, allow-list or prompt that names a tool. Resource URIs are unchanged.

| 1.0.x name | 2.0.0 name |
|---|---|
| `who-am-i-tool` | `who_am_i` |
| `list-products-tool` | `list_products` |
| `search-products-tool` | `search_products` |
| `get-product-tool` | `get_product` |
| `lookup-barcode-tool` | `lookup_barcode` |
| `list-categories-tool` | `list_categories` |
| `list-locations-tool` | `list_locations` |
| `list-warehouses-tool` | `list_warehouses` |
| `list-low-stock-tool` | `list_low_stock` |
| `adjust-stock-tool` | `adjust_stock` |
| `list-orders-tool` | `list_orders` |
| `get-order-tool` | `get_order` |
| `create-order-tool` | `create_order` |
| `list-suppliers-tool` | `list_suppliers` |
| `list-purchase-orders-tool` | `list_purchase_orders` |
| `get-purchase-order-tool` | `get_purchase_order` |
| `create-purchase-order-tool` | `create_purchase_order` |
| `send-purchase-order-tool` | `send_purchase_order` |
| `receive-purchase-order-tool` | `receive_purchase_order` |
| `list-work-orders-tool` | `list_work_orders` |
| `start-work-order-tool` | `start_work_order` |
| `create-product-tool` | `create_product` |
| `reorder-helper-prompt` (prompt) | `reorder_helper` |

New in 2.0.0: `email_order_invoice`, `record_payment`, `list_shipments`, `create_shipment`, `submit_purchase_order_for_approval`, `list_pending_approvals`, `decide_approval` and `delete_work_order`. The catalog is in [docs/mcp/README.md](docs/mcp/README.md). `laravel/mcp` is now a production dependency, so the MCP server is included in the release package and the Docker image.

### Plugin assets

Plugin UI bundles are published to `public/plugin-assets/{slug}` instead of `public/plugins/{slug}`. The app republishes active plugins' bundles and removes the old copies itself. On cPanel, the new `public_html/index.php` from the release package is required (step 5 above).

Plugins now declare the minimum Inventoros version in `requires`, and it is enforced. The bundled `hello-world` plugin requires 2.0.0. Build-time plugins that imported the removed components `DropdownLink`, `NavLink`, `ResponsiveNavLink`, `SidebarNavItem` or `SidebarUserProfile` must be updated. See [docs/PLUGIN_DEVELOPMENT.md](docs/PLUGIN_DEVELOPMENT.md).

### API reference access

The interactive API reference at `/docs/api` now requires a signed-in user outside the `local` environment. Set `API_DOCS_PUBLIC=true` to make it public again. The OpenAPI file is also in the repository at `docs/api/openapi.yaml`.

### Plugin marketplace

Plugins > Marketplace installs and updates plugins from inventoros.com. Every package is verified against the marketplace signing public key configured in `config/marketplace.php` (override it with `INVENTOROS_MARKETPLACE_PUBLIC_KEY`); the app never trusts a key the marketplace advertises. If the Marketplace tab says installs are off, no key is configured. Paid plugins need an inventoros.com account connected on the same tab.

- **Signed manifest.** The marketplace signs each package's slug, version and sha256 together, not just the ZIP bytes. The package's `plugin.json` must declare the signed version, and an update must be newer than the installed version, so an older package cannot be installed as an update. Marketplaces or mirrors that sign only the ZIP bytes are refused.
- **Marketplace updates only replace marketplace installs.** Update is offered only for plugins installed from the marketplace. A plugin you uploaded or copied in by hand is never overwritten, even if it has the same name as a marketplace plugin; delete it and install the marketplace version to switch.
- **One organization administers plugins.** Plugins are shared by every organization on an installation, so installing, updating, uploading, activating, deactivating and deleting them needs Manage Plugins in the plugin administrator organization: `INVENTOROS_PLUGIN_ADMIN_ORG`, or the first organization when unset. Single-organization installs are unaffected; on multi-organization installs, other organizations' admins can only browse plugins.
- **Plugin pages always need sign-in.** A page registered with `register_page()` is always behind `auth`; a plugin's own `middleware` is added after it instead of replacing it. Pages under a reserved prefix (`api`, `portal`, `install`, `graphql`, `mcp`, `webhooks`, `plugins`, `plugin-assets`, the sign-in and password pages) or at a URI the app already uses are skipped and logged. Plugin author links are shown only for http(s) URLs, and marketplace icons only for https ones.

### Currencies and money

- **Products stored as USD by default move to your organization's currency.** In 1.0.x a product created through the REST API, GraphQL, MCP or the products import without a currency was saved as USD, whatever your organization's currency. The 2.0.0 migrations change every USD product of an organization whose currency is not USD to that organization's currency. Nothing changes for organizations whose currency is USD. **If you really price some products in USD in a non-USD organization**, open each one after upgrading and set its USD price under the product's additional currency prices (and set its currency back if you want USD to be its main one). New products created without a currency now take the organization's currency.
- **Orders default to the organization's currency** on every surface. The REST API, GraphQL and MCP used to default to USD.
- **Order lines without a unit price are priced in the order's currency**: the product's own price when the order is in the product's currency, otherwise the product's price for that currency. If the product has no price in the order's currency the order is rejected (`422` on `items.N.unit_price`) instead of silently using a price in another currency. Send `unit_price` for such lines.
- **Money takes at most two decimal places.** Unit prices and costs, taxes, shipping, discount values (percentages included) and payment amounts with more decimals are rejected on the web forms, REST, GraphQL, MCP and imports. Calculated amounts are rounded half up to the cent (they used to be truncated).
- **Totals are shown per currency.** The Outstanding Balances, dead stock, inventory valuation and category performance reports and the dashboard money figures no longer add amounts in different currencies together. Each figure is in your organization's currency with the other currencies listed beside it, and report exports have a Currency column (one row per currency where rows are totals).
- **A failed import row no longer affects the others.** Each order in an order import commits on its own, and a product or user row that fails in the database is reported against that row while the rest of the file imports (on PostgreSQL it used to abort the rest).

### Other behaviour changes

- `/api/v1` accepts bearer tokens only. Browser sessions are not accepted there.
- `POST /api/v1/purchase-orders/{id}/send` emails the supplier and returns `Purchase order sent`.
- **Queued and sent emails.** Sending a purchase order, invoice or shipment email records `queued_at` (`invoice_queued_at`, `customer_notification_queued_at`); `sent_at` (`invoice_sent_at`, `customer_notified_at`) is set only when the mail is delivered, so it is empty until the queue runs. The MCP send tools say the email was queued and return `queued_at`. A purchase order still moves from draft to sent when it is queued.
- **Plugins and the route cache.** Activating, deactivating or deleting a plugin rebuilds a cached route table (`php artisan route:cache`) so the plugin's pages appear or disappear straight away. If the rebuild fails, routes are left uncached.
- Invoice PDFs are named after the invoice number (`INV-000001.pdf`).
- REST reads of categories, locations and stock adjustments require `manage_categories`, `manage_locations` and `manage_stock`.
- The MCP `create_product` tool requires `create_products`.
- `settings.organization.users.*` redirects to `/users`, and the webhook `create` and `edit` routes were removed.
- **Organization email settings.** Each organization's provider is used only for its own mail and never changes the instance's mail config. Mail carrying a sign-in or set-password link (staff password resets and invitations, portal invitations and password resets) always uses the instance mailer, so configure `MAIL_*` in `.env`. An organization with no usable provider uses the instance mailer. SendGrid now sends through its SMTP relay (`smtp.sendgrid.net`, user `apikey`) and "PHP mail" through the sendmail transport (`MAIL_SENDMAIL_PATH`). Mailgun needs `composer require symfony/mailgun-mailer symfony/http-client`; without them the instance mailer is used and a warning is logged.
- **Document email limits.** Sending purchase orders and invoices (web, REST, MCP) is limited to 10 per user per minute and 500 per organization per day by default. Over the limit REST returns `429` with `error: rate_limited`. Adjust with the `DOCUMENT_EMAILS_*` variables above.
- **API token minting.** `POST /api/v1/tokens` never grants more than the calling token and the user's permissions. Without `abilities`, an admin using an unrestricted token still gets `*`; a non-admin gets the explicit list of permissions they hold (previously `[]`), and a non-admin holding none gets `422`. Requesting `*` or an ability the caller lacks is a `422`. Existing tokens are unchanged.
- **Approvals and tokens.** GraphQL `decideApproval` and `requestStockAdjustmentApproval` check token abilities like REST, and the approval listings (REST, GraphQL, MCP) only show request types the token has an ability for.
- **Order self-approval.** The user who created an order cannot approve it (REST `422`, `error: self_approval`) unless they are an admin and the organization's approvals setting "admins can self-approve" is on (the default).
- **User management.** Users with `edit_users` or `delete_users` who are not admins get `403` when editing or deleting an admin, a manager, or anyone holding permissions they lack.
- **Scheduled reports.** Recipients must hold `view_reports` and the view permission of the report's data source; others are rejected when saving and skipped when sending. Review existing schedules after upgrading.
- **Exports.** A generated export can only be downloaded by the user who requested it, or by an admin.
- **Purchase order totals.** Editing a purchase order always recomputes its total from subtotal, tax and shipping, including REST and GraphQL edits that change only shipping or tax. A PO whose new total reaches the approval threshold needs approval again before it can be sent, and a cleared tax or shipping is stored as 0.
- **Spreadsheet imports.** Laravel Excel is now 4.x with PhpSpreadsheet 5.x (fixes CVE-2026-59933 and related advisories). Imports pick the CSV, XLSX or XLS reader from the file's detected content, not its name, and the file name must end in `.csv`, `.txt`, `.xlsx` or `.xls`. Custom code that implements Laravel Excel concerns must match the 4.x signatures (for example `ToCollection::collection(): void`).
- **Shipments and warehouse access.** A user limited to some warehouses can only create shipments from those warehouses (web, REST and MCP return 403 otherwise).
- **Warehouse permissions.** The web warehouse pages check `create_warehouses`, `edit_warehouses` and `delete_warehouses` for creating, editing and deleting, as the REST API already did. Before, `view_warehouses` alone allowed all three. Grant those permissions to any custom role that should keep managing warehouses.
- **Database errors.** A database error during a web, REST or GraphQL action is logged and answered with the standard error page or an HTTP 500, instead of showing its SQL in a message or returning it in a 422. REST clients that treated every 422 from receiving, transfers, audits, work orders or order actions as a business refusal now see real failures as 500s.
- **Products sold by variant.** Their stock is the sum of their active variants' stock wherever stock is shown, valued or compared with `min_stock` and `reorder_point` (dashboard, reports, reorder suggestions, low-stock filters). `products.stock` itself is unchanged, and the REST and GraphQL `stock` field still returns it; read `total_stock` for the figure the app shows.

### License

Inventoros 2.0.0 and later are licensed under the [GNU Affero General Public License v3.0 only](LICENSE) (AGPL-3.0-only). Releases up to and including 1.0.8 remain under MIT. If you modify Inventoros and let others use it over a network, the AGPL requires you to offer them the source of your modified version.

### Getting help

- [GitHub Issues](https://github.com/Inventoros/Inventoros/issues)
- [Contributing Guide](CONTRIBUTING.md)
