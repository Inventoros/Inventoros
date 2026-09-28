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
- [Other behaviour changes](#other-behaviour-changes)
- [License](#license)

### Requirements

- **PHP 8.4.1 or newer. PHP 8.5 is not supported yet** (`phpoffice/phpspreadsheet` does not install on it). The 1.0.8 release package already needed PHP 8.4.1, so most 1.0.8 installs meet this.
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

**Payment status on older orders.** Payments were not recorded before 2.0.0, so orders created before the upgrade show the payment status **Not tracked** rather than Unpaid. If those orders were paid, an admin can mark them paid in bulk.

### Scheduler and queue worker

**The scheduler cron entry is required.** Without it, queued email is never sent and cycle counts, scheduled reports, shipment tracking and reorder checks never run. Add one entry that runs every minute:

```text
* * * * * cd /path/to/inventoros && php artisan schedule:run >> /dev/null 2>&1
```

It runs `inventory:run-cycle-counts` (hourly), `reports:send-scheduled` (every 15 minutes), `shipping:track` (every 30 minutes), `inventory:check-reorder-points` (daily) and the nightly `activity-logs:prune` and `webhooks:prune`.

**The queue.** Purchase order and invoice emails, scheduled reports, approval, shipment and user activity alerts, webhook deliveries and import jobs go through the database queue. When the app says an email was sent, it has been queued; it goes out when the queue is next processed.

- **cPanel and other shared hosting:** in 2.0.0 the scheduler also works through the database queue every minute, so the `schedule:run` entry above is all you need. No separate worker is required.
- **VPS:** run a dedicated worker so jobs are processed as soon as they are queued. Install the systemd units in `deploy/systemd/` (`inventoros-worker.service`, plus `inventoros-scheduler.timer` if you prefer it to cron). See [installation-vps.md](docs/site/sections/installation-vps.md#scheduler-and-queue-worker). Run `php artisan queue:restart` after each deploy.
- **Docker:** `docker-compose.prod.yml` already runs `worker` and `scheduler` containers.

### New environment variables

All are optional. `.env.example` lists each one with its default.

| Variable | Default | Purpose |
|---|---|---|
| `API_DOCS_PUBLIC` | `false` | Make `/docs/api` public outside `local`. |
| `INVENTOROS_MARKETPLACE_URL` | `https://inventoros.com` | Marketplace the app installs plugins from. |
| `INVENTOROS_MARKETPLACE_PUBLIC_KEY` | see [Plugin marketplace](#plugin-marketplace) | Ed25519 public key marketplace packages are verified against. |
| `INVENTOROS_MARKETPLACE_CACHE_SECONDS` | `300` | How long the catalog is cached. |
| `INVENTOROS_MARKETPLACE_TIMEOUT` | `20` | Marketplace request timeout, in seconds. |
| `INVENTOROS_MARKETPLACE_MAX_DOWNLOAD_BYTES` | 50 MB | Largest plugin package the app downloads. |
| `INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP` | `false` | Let the updater continue when no database backup method works. |
| `INVENTOROS_UPDATE_MAX_ENTRIES`, `INVENTOROS_UPDATE_MAX_BYTES` | 50000, 300 MB | Update archive limits. |
| `INVENTOROS_PLUGIN_MAX_ENTRIES`, `INVENTOROS_PLUGIN_MAX_BYTES` | 2000, 50 MB | Plugin package limits. |
| `LOW_STOCK_ALERT_COOLDOWN_MINUTES` | `1440` | Minimum gap between low-stock alerts for one product. |
| `REPORTS_MAX_ROWS` | `10000` | Row cap for any report. |
| `REPORTS_PDF_MAX_ROWS` | `1000` | Row cap for report PDFs; CSV and Excel carry the full set. |
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

### Other behaviour changes

- `/api/v1` accepts bearer tokens only. Browser sessions are not accepted there.
- `POST /api/v1/purchase-orders/{id}/send` emails the supplier and returns `Purchase order sent`.
- Invoice PDFs are named after the invoice number (`INV-000001.pdf`).
- REST reads of categories, locations and stock adjustments require `manage_categories`, `manage_locations` and `manage_stock`.
- The MCP `create_product` tool requires `create_products`.
- `settings.organization.users.*` redirects to `/users`, and the webhook `create` and `edit` routes were removed.

### License

Inventoros 2.0.0 and later are licensed under the [GNU Affero General Public License v3.0 only](LICENSE) (AGPL-3.0-only). Releases up to and including 1.0.8 remain under MIT. If you modify Inventoros and let others use it over a network, the AGPL requires you to offer them the source of your modified version.

### Getting help

- [GitHub Issues](https://github.com/Inventoros/Inventoros/issues)
- [Contributing Guide](CONTRIBUTING.md)
