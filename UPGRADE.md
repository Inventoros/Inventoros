# Upgrade Guide

## Upgrading from v1.1 to v2.0

Inventoros v2.0 upgrades the core framework stack and adds three major features. This guide covers everything you need to do to upgrade an existing v1.1 installation.

### Requirements

- **PHP 8.3+** (unchanged)
- **Node.js 18+** (unchanged)
- **Composer 2.x**

### Framework Changes

| Package | v1.1 | v2.0 |
|---------|------|------|
| Laravel | 12.x | **13.x** |
| Inertia (PHP) | 2.x | **3.x** |
| Inertia (Vue) | 2.x | **3.x** |
| Tinker | 2.x | **3.x** |
| google2fa-laravel | 2.x | **3.x** |

### Step-by-Step Upgrade

#### 1. Back Up Your Database

```bash
# If using the built-in backup system
php artisan app:update --backup

# Or manually
cp database/database.sqlite database/database.sqlite.backup
# For MySQL/PostgreSQL, use mysqldump or pg_dump
```

#### 2. Drain Queues

Laravel 13 changed the job serialization format. Jobs queued by v1.1 will fail on the v2.0 worker. **Process all pending jobs before upgrading.**

```bash
php artisan queue:work --stop-when-empty
```

Wait until all jobs are processed before continuing.

#### 3. Pull the Latest Code

```bash
git pull origin main
```

#### 4. Update PHP Dependencies

```bash
composer update
```

If you encounter conflicts, ensure your `composer.json` has:

```json
{
    "require": {
        "laravel/framework": "^13.0",
        "inertiajs/inertia-laravel": "^3.0",
        "laravel/tinker": "^3.0",
        "pragmarx/google2fa-laravel": "^3.0"
    }
}
```

#### 5. Update JavaScript Dependencies

```bash
npm install
npm run build
```

Your `package.json` should have:

```json
{
    "devDependencies": {
        "@inertiajs/vue3": "^3.0.0"
    }
}
```

#### 6. Run Database Migrations

v2.0 adds several new tables and columns:

```bash
php artisan migrate
```

**New tables created:**
- `warehouses` — Multi-warehouse support
- `warehouse_user` — User-warehouse access pivot
- `product_components` — Kit/assembly bill of materials
- `work_orders` — Assembly production tracking
- `work_order_items` — Work order component consumption
- `saved_reports` — Custom report builder templates

**Columns added to existing tables:**
- `products.type` — Product type (standard, kit, assembly)
- `products.warehouse_id` — Warehouse assignment
- `product_locations.warehouse_id` — Location-warehouse relationship
- `orders.warehouse_id` — Order fulfillment warehouse
- `orders.customer_id` — Customer foreign key
- `stock_transfers.from_warehouse_id` — Source warehouse
- `stock_transfers.to_warehouse_id` — Destination warehouse
- `stock_transfers.is_inter_warehouse` — Inter-warehouse flag
- `stock_transfers.shipping_method` — Shipping details
- `stock_transfers.tracking_number` — Tracking info
- `stock_transfers.shipped_at` — Ship timestamp
- `stock_transfers.estimated_arrival` — ETA

#### 7. Update Blade Template (Required)

If you have customized `resources/views/app.blade.php`, update the title tag:

```blade
<!-- Before (v1.1) -->
<title inertia>{{ config('app.name', 'Laravel') }}</title>

<!-- After (v2.0) -->
<title data-inertia>{{ config('app.name', 'Laravel') }}</title>
```

This is required by Inertia v3. The `inertia` attribute was renamed to `data-inertia`.

#### 8. Clear Caches

```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
```

#### 9. Rebuild Frontend Assets

```bash
npm run build
```

#### 10. Verify the Upgrade

```bash
# Check Laravel version
php artisan --version
# Should show: Laravel Framework 13.x.x

# Run the test suite (optional)
php artisan test
```

### Breaking Changes

#### Laravel 13

- **Default pagination changed from 15 to 25 items per page.** Inventoros explicitly sets page sizes in all controllers, so this should not affect you. If you have custom controllers using bare `->paginate()`, add an explicit count: `->paginate(15)`.

- **Cache/session prefix format changed.** If upgrading a production system, set these in your `.env` to preserve existing sessions:
  ```env
  CACHE_PREFIX=your_existing_prefix
  SESSION_COOKIE=your_existing_cookie_name
  ```

- **Password reset email subject changed** from "Reset Password Notification" to "Reset your password". If you have email filters or tests checking this subject line, update them.

#### Inertia v3

- **`Inertia::lazy()` removed.** Use `Inertia::optional()` instead. Inventoros does not use `lazy()`, so no action needed unless you added custom controllers.

- **Global router events renamed.** If you added custom JavaScript event handlers:
  - `'invalid'` is now `'httpException'`
  - `'exception'` is now `'networkError'`

- **`router.cancel()` renamed to `router.cancelAll()`.** Update any custom JavaScript calling `router.cancel()`.

- **Axios no longer bundled with Inertia.** Inventoros includes Axios as a direct dependency, so this should not affect you. If you import Axios from Inertia's internals, update your imports.

#### google2fa-laravel v3

- No API changes. This is a compatibility release for Laravel 13.

### New Features in v2.0

After upgrading, you'll have access to three new feature areas:

#### Multi-Warehouse Support
- Create and manage multiple warehouses with addresses and settings
- Assign users to specific warehouses (access control)
- Global warehouse switcher in the header to filter all views
- Inter-warehouse stock transfers with shipping/tracking
- Default warehouse per organization

#### Kitting, Bundling & Assembly
- Products now have a `type` field: standard, kit, or assembly
- Kits: virtual products with auto-calculated stock from components
- Assemblies: production work orders that consume components and produce finished goods
- Bill of Materials (BOM) management on product pages
- Work Orders section in sidebar navigation

#### Custom Report Builder
- Create custom reports from 6 data sources (products, orders, stock adjustments, customers, suppliers, purchase orders)
- Select columns, add filters, configure sorting
- Save reports as templates, share with team
- Export reports as CSV
- Live preview while building

#### New API Endpoints

All new features have full REST API support:

```
GET/POST       /api/v1/warehouses
GET/PUT/DELETE /api/v1/warehouses/{id}

GET/POST       /api/v1/work-orders
GET            /api/v1/work-orders/{id}
POST           /api/v1/work-orders/{id}/start
POST           /api/v1/work-orders/{id}/complete
POST           /api/v1/work-orders/{id}/cancel

GET/POST       /api/v1/products/{id}/components
PUT/DELETE     /api/v1/products/{id}/components/{id}

GET/POST       /api/v1/reports
GET/PUT/DELETE /api/v1/reports/{id}
GET            /api/v1/reports/{id}/export
```

#### New Permissions

Add these to your custom roles as needed:

- `view_warehouses` — View warehouse list
- `create_warehouses` — Create warehouses
- `edit_warehouses` — Edit warehouse settings
- `delete_warehouses` — Delete warehouses
- `manage_warehouse_users` — Assign users to warehouses
- `manage_returns` — Manage returns and exchanges

### Plugin Compatibility

If you have custom plugins, check for:

1. **Laravel 13 namespace changes**: `VerifyCsrfToken` middleware is now `PreventRequestForgery` (the old name still works as an alias).
2. **Inertia v3 testing**: If your plugin tests use `assertInertia()`, they should continue to work unchanged.
3. **New product type field**: Queries on the `products` table should account for the new `type` column if filtering.

### Rollback

If you need to roll back:

```bash
# Restore from backup
php artisan app:update --restore

# Or revert to v1.1 tag
git checkout v1.1
composer install
npm install && npm run build
php artisan migrate:rollback --step=4
```

### Getting Help

- [GitHub Issues](https://github.com/Inventoros/Inventoros/issues)
- [Contributing Guide](CONTRIBUTING.md)
