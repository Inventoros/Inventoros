# Inventoros Plugin Development Guide

Plugins extend Inventoros without changing core files. A plugin is a folder under `/plugins` with a manifest, a PHP file that registers WordPress-style actions and filters, optional lifecycle files, and an optional pre-built browser bundle for its UI.

Everything in this guide is backed by code and tests: the hook tables are checked against `app/Services/HookRegistry.php`, which is itself checked against every hook the application fires (`tests/Feature/PluginHookDocumentationTest.php`). The `plugins/hello-world` plugin is a working example of every part.

## Table of contents

1. [Plugin structure](#plugin-structure)
2. [The manifest (plugin.json)](#the-manifest-pluginjson)
3. [Installing, activating and removing](#installing-activating-and-removing)
4. [Installing from the marketplace](#installing-from-the-marketplace)
5. [Actions and filters](#actions-and-filters)
6. [Lifecycle files](#lifecycle-files)
7. [Action reference](#action-reference)
8. [Filter reference](#filter-reference)
9. [Permissions](#permissions)
10. [Plugin UI](#plugin-ui)
11. [MCP tools](#mcp-tools)
12. [Outbound webhook events](#outbound-webhook-events)
13. [Building and packaging a plugin](#building-and-packaging-a-plugin)
14. [Publishing to the marketplace](#publishing-to-the-marketplace)
15. [Licences for paid plugins](#licences-for-paid-plugins)
16. [Examples](#examples)
17. [Best practices](#best-practices)
18. [Security notes](#security-notes)
19. [Debugging](#debugging)

## Plugin structure

```text
my-plugin/
├── plugin.json          # Required: manifest
├── Plugin.php           # Required: loaded on every request while active (name set by main_file)
├── hooks/               # Optional: lifecycle files
│   ├── activate.php     # Runs once on activation
│   ├── deactivate.php   # Runs once on deactivation
│   └── uninstall.php    # Runs once when the plugin is deleted
├── src/                 # Optional: your own PHP classes (require them from Plugin.php)
├── ui/                  # Optional: source of the browser bundle (not used at runtime)
├── dist/                # Optional: the pre-built browser bundle, published while active
└── README.md
```

The folder name is the plugin's **slug** (`my-plugin`). It may contain letters, digits, `.`, `_` and `-`, and must start with a letter or digit.

### The main file

`Plugin.php` runs once for every application Inventoros boots, and one PHP process can boot more than one: `php artisan route:cache` and `php artisan optimize` boot a second application to collect the routes they cache. Everything the file registers (actions, filters, pages, menu items, widgets) has to be registered again there, or the plugin's pages are missing from the route cache and answer 404.

The safest shape is a file that returns a registration closure. Inventoros requires the file once per process and calls the closure once per application:

```php
<?php

// plugins/my-plugin/Plugin.php
require_once __DIR__.'/src/helpers.php';   // named functions and classes: load once

return function (string $slug, array $manifest): void {
    add_action('product_created', fn ($product, $user) => my_plugin_log($product));

    register_page('plg.my-plugin.index', 'Plugin::my-plugin/Index', ['uri' => '/p/my-plugin']);
};
```

A file that registers directly at the top level, like `plugins/hello-world/Plugin.php`, also works: it is required again in each application. It must then not declare named functions, classes, interfaces, traits or enums at its top level (declaring one twice is a fatal PHP error). Put those in `src/` and `require_once` them, or guard them with `function_exists()` / `class_exists()`. A top-level file that does declare them is loaded only in the first application, and a warning is logged.

## The manifest (plugin.json)

```json
{
    "name": "My Plugin",
    "description": "What the plugin does",
    "version": "1.0.0",
    "author": "Your Name",
    "author_url": "https://example.com",
    "requires": "2.0.0",
    "requires_php": "8.2",
    "main_file": "Plugin.php",
    "ui": {
        "entry": "plugin.js",
        "styles": ["plugin.css"]
    }
}
```

| Field | Required | Meaning |
|-------|----------|---------|
| `name` | Yes | Display name. |
| `description` | Yes | Short description for the Plugins page. |
| `version` | Yes | The plugin's version. Also used to bust the browser cache of its UI bundle. |
| `author` | Yes | Author name. |
| `author_url` | No | Author website. |
| `requires` | No | Minimum Inventoros version, compared with the `VERSION` file. Activation and upload are refused on older installs. |
| `requires_php` | No | Minimum PHP version. Activation and upload are refused on older PHP. |
| `main_file` | No | The PHP file loaded on every request while active. Defaults to `Plugin.php` and must sit in the plugin's root folder. |
| `ui` | No | The plugin's pre-built browser bundle, see [Plugin UI](#plugin-ui). `entry` is a `.js`/`.mjs` file and `styles` a list of `.css` files, both relative to `dist/`. |

Versions may be written `1.2`, `1.2.3`, `v1.2.3` or `1.2.3-beta`. A value that is not a version number is refused with an error, so a typo cannot silently disable the check.

## Installing, activating and removing

- **Install** from **Plugins > Marketplace** (see [Installing from the marketplace](#installing-from-the-marketplace)), by copying the folder into `/plugins`, or by uploading a ZIP from **Admin > Plugins**. Uploads are off by default (see [Security notes](#security-notes)). The ZIP must contain exactly one top-level folder, which becomes the slug.
- **Activate** from the Plugins page. In order, Inventoros:
  1. checks `requires` and `requires_php`,
  2. validates the `ui` block,
  3. loads `main_file`,
  4. runs `hooks/activate.php`,
  5. publishes `dist/` to `public/plugin-assets/{slug}/`,
  6. fires `plugin_activated` and `plugin_activated_{slug}`,
  7. marks the plugin active.

  If any step throws, the plugin stays inactive, its published files are removed and the Plugins page shows the error.

  Over SSH the same lifecycle runs with `php artisan plugin:activate {slug}` and `php artisan plugin:deactivate {slug}`.
- **Deactivate**: fires `plugin_deactivated` and `plugin_deactivated_{slug}`, runs `hooks/deactivate.php`, marks the plugin inactive and removes `public/plugin-assets/{slug}/`. The plugin is deactivated even if its own code throws; the page then shows a warning.
- **Delete**: fires `plugin_uninstalling` and `plugin_uninstalling_{slug}`, deactivates the plugin, runs `hooks/uninstall.php` (whether or not the plugin was active), then removes the plugin's permissions (`{slug}.*`, see [Permissions](#permissions)) from every role and permission set, its published files, its database record and its folder. The files are removed even if the plugin's cleanup throws; the page then shows a warning.

## Installing from the marketplace

Admins with the **Manage Plugins** permission can install plugins from the Inventoros marketplace at [inventoros.com/marketplace](https://inventoros.com/marketplace) without handling a ZIP: open **Plugins > Marketplace**, then choose **Install** or **Install and activate**.

What happens on install:

1. The app downloads the plugin's latest published package from the marketplace, server side.
2. It verifies the package's detached Ed25519 signature against the marketplace public key in `config/marketplace.php` (`INVENTOROS_MARKETPLACE_PUBLIC_KEY`). It never trusts a key the marketplace advertises.
3. It checks the package's sha256 against the download and the catalog listing.
4. It installs the package through the same path as a manual upload: the same path-traversal, entry-count and size checks, the single top-level folder (which must match the plugin's slug) and the `requires` / `requires_php` checks.
5. With **Install and activate**, it then runs the normal activation lifecycle.

Because every package is signed, marketplace installs work even when manual ZIP uploads are disabled. If no marketplace public key is configured, marketplace installs are refused.

**Updates.** The Marketplace tab shows the installed and latest version of each plugin. **Update** downloads and verifies the new version first, then deactivates the plugin (running `hooks/deactivate.php`), swaps its files and activates it again. The plugin's database record and its own data are kept, and `hooks/uninstall.php` is not run. If the new version fails to activate, the previous files are restored and re-activated.

**Paid plugins.** Free plugins install without an account. To install a paid plugin you own, create a marketplace connection token on your inventoros.com account page and paste it into **Plugins > Marketplace**. The token is stored encrypted for your organization and can be disconnected at any time.

**Configuration** (all optional):

```bash
INVENTOROS_MARKETPLACE_URL=https://inventoros.com   # must be a bare https origin
INVENTOROS_MARKETPLACE_PUBLIC_KEY=base64-ed25519-public-key
INVENTOROS_MARKETPLACE_CACHE_SECONDS=300
INVENTOROS_MARKETPLACE_MAX_DOWNLOAD_BYTES=52428800
```

The app only ever requests the configured origin over https, and never follows redirects.

**Maintainers: the marketplace signing key.** The key pair is generated on the marketplace site with `php artisan marketplace:keygen`. The secret goes only into the site's production environment as `MARKETPLACE_SIGNING_SECRET_KEY`. The public key is then committed as the default of `public_key` in this repository's `config/marketplace.php` (public keys are safe to publish), so every release trusts it out of the box.

## Actions and filters

```php
// Actions run code at a point in the application.
add_action(string $tag, callable $callback, int $priority = 10): void;
do_action(string $tag, ...$args): void;
has_action(string $tag): bool;
remove_action(string $tag, ?callable $callback = null): void;

// Filters receive a value and must return it (changed or not).
add_filter(string $tag, callable $callback, int $priority = 10): void;
apply_filters(string $tag, mixed $value, ...$args): mixed;
has_filter(string $tag): bool;
remove_filter(string $tag, ?callable $callback = null): void;
```

Callbacks receive the arguments listed in the reference tables below, in that order. You may accept fewer. Lower priorities run first:

```php
add_action('product_created', fn ($product) => Log::info('first'), 5);
add_action('product_created', fn ($product) => Log::info('second'));      // 10
add_action('product_created', fn ($product) => Log::info('last'), 20);
```

You can fire your own hooks too, so other plugins can extend yours. Prefix them with your slug:

```php
do_action('my_plugin_report_sent', $report);
```

## Lifecycle files

Each file is plain PHP, run once with `require` in an isolated scope (no `$this`). Throwing aborts activation; on deactivation and deletion it is reported as a warning.

`hooks/activate.php` runs after `Plugin.php` has loaded. Create tables and defaults here, and keep it safe to run twice:

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasTable('my_plugin_notes')) {
    Schema::create('my_plugin_notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        $table->text('body');
        $table->timestamps();
    });
}
```

`hooks/deactivate.php` clears caches and stops scheduled work. Keep data; the plugin may come back:

```php
<?php

use Illuminate\Support\Facades\Cache;

Cache::forget('my_plugin_summary');
```

`hooks/uninstall.php` removes everything the plugin created:

```php
<?php

use Illuminate\Support\Facades\Schema;

Schema::dropIfExists('my_plugin_notes');
```

## Action reference

"Web form" and "web UI" hooks fire only from the Inertia screens; the REST, GraphQL and MCP surfaces do not fire them.

### Domain change hooks

The created, updated and deleted hooks of products, variants, orders, purchase orders, customers, suppliers, categories, locations and warehouses, and `stock_changed`, fire from the model layer, so they fire the same way whichever surface made the change: the web screens, bulk actions, REST, GraphQL, MCP, imports, console and scheduled commands. Use them, not the web form hooks, for anything that must see every change (channel sync, accounting, outbound integrations).

- They fire **after the transaction commits**, and never when it rolls back.
- They fire **once per record per transaction**. A product saved three times in one request, or an order whose totals are written after its items, is announced once, with the final values.
- A record created in a transaction is announced by its `*_created` hook only; the saves that complete it are not `*_updated` edits.
- `$user` is the signed-in user, or null for queued jobs and commands.
- A change of on-hand stock alone is not a `product_updated` or `variant_updated`: it fires `stock_changed`.

`stock_changed` receives the product, the variant (or null) and the change:

```php
add_action('stock_changed', function ($product, $variant, array $change) {
    // $change = [
    //     'before' => 20,                 // on-hand before the first change in the transaction
    //     'after' => 17,                  // and after the last one
    //     'locations' => [                // bins that moved, by location id
    //         4 => ['before' => 12, 'after' => 9],
    //     ],
    // ];
    // A move between two bins leaves before === after and lists both bins.
    my_plugin_queue_inventory_push($product->id, $variant?->id);
});

| Action | Arguments | When |
|--------|-----------|------|
| `plugin_loaded` | `$slug`, `$manifest` | After a plugin's main file is loaded (at boot for every active plugin, and on activation). |
| `plugin_activated` | `$slug` | Any plugin is activated, after its `hooks/activate.php` ran. Throwing aborts the activation. |
| `plugin_activated_{slug}` | none | A specific plugin is activated, for example `plugin_activated_my-plugin`. |
| `plugin_deactivated` | `$slug` | Any plugin is deactivated, before its `hooks/deactivate.php` runs. |
| `plugin_deactivated_{slug}` | none | A specific plugin is deactivated. |
| `plugin_uninstalling` | `$slug` | Any plugin is about to be deleted, before its `hooks/uninstall.php` runs. |
| `plugin_uninstalling_{slug}` | none | A specific plugin is about to be deleted. |
| `product_created` | `$product`, `$user` | A product is created on any surface (web, REST, GraphQL, MCP, import), once it and its options and variants are committed. `$user` may be null. |
| `product_before_create` | `$validated_data`, `$request` | Before a product is created from the web form. |
| `product_after_create` | `$product`, `$request` | After a product is created from the web form. |
| `product_before_update` | `$product`, `$validated_data`, `$request` | Before a product is updated from the web form. `$product` still holds the old values. |
| `product_updated` | `$product`, `$user` | A product is edited on any surface (web, bulk edit, REST, GraphQL, import), once per transaction, after commit. Stock moves alone fire `stock_changed` instead. |
| `product_after_update` | `$product`, `$request` | After a product is updated from the web form. |
| `product_before_delete` | `$product`, `$request` | Before a product is deleted from the web UI. |
| `product_deleted` | `$product`, `$user` | A product is deleted on any surface (web, bulk delete, REST, GraphQL), after commit. |
| `product_after_delete` | `$product`, `$request` | After a product is deleted from the web UI. |
| `product_viewed` | `$product`, `$user` | The product detail page is viewed. |
| `product_list_viewed` | `$products`, `$user` | The product list page is viewed (`$products` is the paginator). |
| `variant_created` | `$variant`, `$user` | A product variant is created on any surface, after commit. |
| `variant_updated` | `$variant`, `$user` | A product variant is edited on any surface, once per transaction, after commit. Stock moves alone fire `stock_changed` instead. |
| `variant_deleted` | `$variant`, `$user` | A product variant is deleted on any surface, after commit. |
| `stock_changed` | `$product`, `$variant`, `$change` | A product's or variant's on-hand stock, or one of the product's location bins, changed on any surface (adjustments, orders, receipts, transfers, returns, audits, imports, commands). Once per product or variant per transaction, after commit. See [Domain change hooks](#domain-change-hooks). |
| `stock_adjusted` | `$stock_adjustment`, `$product` | A stock adjustment is committed. `$product` is null for a variant adjustment. |
| `low_stock_alert` | `$product` | A product's stock drops to or below its minimum. |
| `out_of_stock_alert` | `$product` | A product's stock reaches zero. |
| `variant_low_stock_alert` | `$variant` | A variant with its own minimum stock drops to or below it. Variants without one count toward the product's `low_stock_alert`. |
| `warehouse_low_stock_alert` | `$product`, `$warehouse`, `$on_hand` | A product's on-hand quantity in one warehouse drops to or below its minimum stock level for that warehouse. |
| `order_created` | `$order`, `$user` | An order and all its items are created. |
| `order_updated` | `$order`, `$user` | An existing order is saved on any surface, once per transaction, after commit. Not fired while the order is being created. |
| `order_deleted` | `$order`, `$user` | An order is deleted on any surface, after commit. |
| `order_status_changed` | `$order`, `$old_status`, `$new_status`, `$user` | An order's status changes. |
| `order_approved` | `$order`, `$user` | An order is approved. |
| `order_rejected` | `$order`, `$user` | An order is rejected. |
| `payment_recorded` | `$payment`, `$order`, `$user` | A payment or refund is recorded against an order (after commit). |
| `payment_voided` | `$payment`, `$order`, `$user` | A payment or refund is voided (after commit). |
| `shipment_created` | `$shipment`, `$user` | A shipment is created for an order (after commit). |
| `shipment_delivered` | `$shipment` | A shipment is reported delivered (after commit). |
| `purchase_order_created` | `$purchase_order`, `$user` | A purchase order is created on any surface (web, REST, GraphQL, MCP, reorder suggestions), after commit. |
| `purchase_order_updated` | `$purchase_order`, `$user` | A purchase order is edited, sent, received or cancelled on any surface, once per transaction, after commit. |
| `purchase_order_deleted` | `$purchase_order`, `$user` | A purchase order is deleted on any surface, after commit. |
| `purchase_order_received` | `$purchase_order`, `$user` | A purchase order becomes fully received. |
| `purchase_order_cancelled` | `$purchase_order`, `$user` | A purchase order is cancelled. |
| `customer_created` | `$customer`, `$user` | A customer is created on any surface (web, REST, GraphQL, order import), after commit. |
| `customer_updated` | `$customer`, `$user` | A customer is updated on any surface, once per transaction, after commit. |
| `customer_deleted` | `$customer`, `$user` | A customer is deleted on any surface. |
| `return_created` | `$return_order`, `$user` | A return is created. |
| `return_received` | `$return_order`, `$user` | A return is marked received. |
| `transfer_created` | `$transfer`, `$user` | A stock transfer is created. |
| `transfer_completed` | `$transfer`, `$user` | A stock transfer is completed. |
| `stock_audit_completed` | `$stock_audit`, `$user` | A stock audit is completed. |
| `work_order_completed` | `$work_order`, `$user` | A work order is completed. |
| `approval_requested` | `$approval`, `$subject`, `$user` | A purchase order, stock adjustment or stock transfer is waiting for approval, on any surface, after commit. `$approval` is the `ApprovalService::describe()` array (type, id, reference, title, summary, amount, url, requester). Sales orders waiting for approval are announced by `order_created` with `approval_status` pending. |
| `approval_decided` | `$approval`, `$subject`, `$decision`, `$user`, `$notes` | One of those requests is approved or rejected (`$decision`), on any surface, after commit. Sales orders: `order_approved`, `order_rejected`. |
| `import_finished` | `$type`, `$organization_id`, `$user`, `$result` | A product, order or user import from the Import/Export page finished, inline or queued. `$result` is `['status' => 'completed' or 'failed', 'queued' => bool, 'stats' => [...]]`. |
| `supplier_created` | `$supplier`, `$user` | A supplier is created on any surface (web, REST, GraphQL), after commit. |
| `supplier_updated` | `$supplier`, `$user` | A supplier is updated on any surface, once per transaction, after commit. |
| `supplier_before_delete` | `$supplier`, `$user` | Just before a supplier is deleted, on any surface. |
| `supplier_deleted` | `$supplier`, `$user` | A supplier is deleted on any surface, after commit. |
| `category_created` | `$category`, `$user` | A product category is created on any surface (web, REST, product import), after commit. |
| `category_updated` | `$category`, `$user` | A product category is updated on any surface, once per transaction, after commit. |
| `category_deleted` | `$category`, `$user` | A product category is deleted on any surface, after commit. |
| `location_created` | `$location`, `$user` | A location (bin) is created on any surface (web, REST, product import), after commit. |
| `location_updated` | `$location`, `$user` | A location is updated on any surface, once per transaction, after commit. |
| `location_deleted` | `$location`, `$user` | A location is deleted on any surface, after commit. |
| `warehouse_created` | `$warehouse`, `$user` | A warehouse is created on any surface (web, REST), after commit. |
| `warehouse_updated` | `$warehouse`, `$user` | A warehouse is updated on any surface, once per transaction, after commit. |
| `warehouse_deleted` | `$warehouse`, `$user` | A warehouse is deleted on any surface, after commit. |
| `supplier_viewed` | `$supplier`, `$user` | The supplier detail page is viewed. |
| `supplier_list_viewed` | `$suppliers`, `$user` | The supplier list page is viewed. |
| `dashboard_stats_calculated` | `$stats`, `$user` | The dashboard statistics are calculated (after the `dashboard_stats_data` filter). |
| `dashboard_stats` | `$stats`, `$user` | Alias of `dashboard_stats_calculated`, fired right after it with the same arguments. |
| `dashboard_viewed` | `$user` | The dashboard is viewed. |
| `email_notification_sent` | `$type`, `$user`, `$data` | A notification email is queued. |
| `email_notification_failed` | `$type`, `$user`, `$data`, `$exception` | Queueing a notification email failed. |

## Filter reference

A filter callback receives the value first, then the listed context, and must return the value.

| Filter | Arguments | What it changes |
|--------|-----------|-----------------|
| `product_display_name` | `$name`, `$product` | The product name shown on the product list and detail pages, in global search and as `display_name` in the REST product resource. The stored `name` is unchanged. |
| `product_price_display` | `$price`, `$product` | The price shown on the product list and detail pages and as `display_price` in the REST product resource. `$price` is a float (or null). The stored `price`, order pricing and reports are unchanged. |
| `product_search_query` | `$query`, `$search_term` | Product search on the product list, global search and `GET /api/v1/products?search=`. `$query` is the nested group of OR conditions that match the term: add alternatives with `orWhere()`. Tenant and other filters sit outside the group, so a plugin cannot widen them. |
| `product_list_query` | `$query`, `$request` | The product list query. The organization scope is re-applied afterwards. |
| `product_list_data` | `$products`, `$request` | The paginated products before the list page renders. |
| `product_list_page_data` | `$data`, `$request` | All props of the product list page. |
| `product_show_data` | `$product`, `$user` | The product before the detail page renders. |
| `product_show_page_data` | `$data`, `$product` | All props of the product detail page. |
| `product_store_validation_rules` | `$rules`, `$request` | Validation rules of the web product create form. |
| `product_store_data` | `$validated_data`, `$request` | Validated data before a product is created from the web form. |
| `product_store_response` | `$response`, `$product`, `$request` | The response after a product is created from the web form. |
| `product_update_validation_rules` | `$rules`, `$product`, `$request` | Validation rules of the web product edit form. |
| `product_update_data` | `$validated_data`, `$product`, `$request` | Validated data before a product is updated from the web form. |
| `stock_audit_completing` | `$allowed`, `$stock_audit`, `$user`, `$allow_uncounted` | Whether a stock audit may be completed, on every surface (web, REST), under the audit's row lock and before any recount is booked. Return `true` to allow it; return a reason string to refuse it (the web page shows it as the error, the REST API answers 422 with `"error": "completion_vetoed"` and the reason as `message`), or `false` for a generic refusal. Pass `$allowed` through when you have no objection, so another plugin's refusal stands. See [Holding a stock audit](#holding-a-stock-audit). |
| `order_total_calculation` | `$total`, `$order` | The order total, each time `OrderService` computes it (on create and on every edit, from every surface), after line and order discounts, tax and shipping. `$total` is a 2-decimal string (`subtotal - discount_amount + tax + shipping`); `$order` already holds those parts and, on create, has no `id` yet. Return a number or numeric string. A negative total, or one below what the customer has already paid, is rejected with a validation error. Changing it breaks the `subtotal - discount + tax + shipping = total` identity on the stored order, so prefer adjusting the inputs where you can. |
| `supplier_list_query` | `$query`, `$request` | The supplier list query. The organization scope is re-applied afterwards. |
| `supplier_list_data` | `$suppliers`, `$request` | The paginated suppliers before the list page renders. |
| `supplier_list_page_data` | `$data`, `$request` | All props of the supplier list page. |
| `supplier_before_create` | `$validated_data`, `$request` | Validated data before a supplier is created. |
| `supplier_before_show` | `$supplier`, `$request` | The supplier before its detail page renders. |
| `supplier_before_update` | `$validated_data`, `$supplier`, `$request` | Validated data before a supplier is updated. |
| `dashboard_stats_data` | `$stats`, `$user` | The dashboard statistics array. |
| `dashboard_page_data` | `$data`, `$user` | All props of the dashboard page. |
| `email_notification_data` | `$data`, `$type`, `$user` | The data passed to a notification email. |
| `should_send_email` | `$should_send`, `$type`, `$user`, `$data` | Return false to stop a notification email. |
| `email_mailable_class` | `$mailable_class`, `$type`, `$data` | Supply a Mailable class for a custom notification type. |
| `report_data_sources` | `$sources` | Register extra data sources for the report builder. |
| `report_query_{source}` | `$rows`, `$organization_id`, `$columns`, `$filters`, `$sort` | Return the rows (a Collection) for a data source you registered, for example `report_query_my_source`. |

A `user_permissions` filter is deliberately not offered: letting plugins rewrite a user's permissions would be a privilege-escalation path.

### Holding a stock audit

```php
add_filter('stock_audit_completing', function ($allowed, $audit, $user, $allowUncounted) {
    if ($allowed !== true) {
        return $allowed; // another plugin already refused
    }

    $waiting = my_plugin_lines_awaiting_recount($audit->id);

    return $waiting > 0
        ? "{$waiting} line(s) are waiting for a supervisor recount."
        : true;
});
```

Nothing is booked when completion is refused: the audit stays in progress and stock is unchanged. The callback runs inside the completion transaction, so a write it makes is rolled back with a refusal.

## Permissions

A plugin can check any core permission from `app/Enums/Permission.php`, and can register permissions of its own from its main file:

```php
register_permission('my-plugin.manage', 'Manage My Plugin', 'Can change My Plugin settings', 'My Plugin');
```

| Argument | Meaning |
|----------|---------|
| `$name` | `{plugin-slug}.{ability}`: the slug (lowercase letters, digits, hyphens), a dot, then the ability (lowercase letters, digits, underscores). Core permission names never contain a dot, so a plugin cannot redefine one. Anything else throws `InvalidArgumentException`. |
| `$label`, `$description` | Shown in the role editor. |
| `$category` | The role editor group. Defaults to `Plugins`. |

A registered permission works like a core one: it is listed when roles and permission sets are edited and when API tokens are created, admins hold it, and `$user->hasPermission()`, a page, menu item, placement or widget `permission`, and token abilities all accept it. Registering the same name again replaces its label.

Registrations last while the plugin is active. A deactivated plugin's permissions are not listed and cannot be put on new API tokens; grants already saved on roles stay and apply again when the plugin is reactivated. Deleting the plugin removes every `{slug}.*` name from roles and permission sets.

To translate the label in the role editor, add `permissions.{ability}.label` and `permissions.{ability}.description` to the plugin's runtime messages (see [Translations](#translations)); without them the label you registered is shown.

On Inventoros versions without `register_permission()`, check `function_exists('register_permission')` and fall back to the closest core permission.

## Plugin UI

Every server-side UI registration takes an optional `permission`: a permission name from `app/Enums/Permission.php` or one registered with `register_permission()`, or a list of names meaning "any of". It is enforced on the server. A user who lacks it never receives the entry, and an entry with a permission is hidden from guests. Where an entry takes `data` or `props`, you can pass a closure instead of an array. The closure only runs for users who pass the permission check, so a figure they may not see is never computed.

### Menu items

```php
register_menu_item([
    'label' => 'My Plugin',                          // shown until (or unless) the key resolves
    'label_key' => 'plugins.my-plugin.nav.title',    // optional translation key
    'route' => 'plg.my-plugin.settings',             // or 'url' => 'https://...'
    'permission' => 'manage_plugins',                // optional
    'position' => 100,
]);
```

Items whose route does not exist are dropped. Submenu entries (`'submenu' => [[...], ...]`) are filtered by their own `permission` the same way, and take a `label_key` too.

`label_key` translates the item: the browser shows the key from your bundle's messages (`plugin.i18n.addMessages('en', { nav: { title: 'My Plugin' } })`, see [Translations](#translations)) in the user's language, falling back to English, and shows `label` until your bundle has loaded or when the key is missing. The key must sit under `plugins.{your-slug}.`; any other key is ignored (and logged), so a plugin cannot borrow or spoof a core string. Plain `label` strings keep working on their own. On cores without this, `label_key` is ignored and `label` is shown.

### Components in existing pages

The server decides where a component goes; the browser bundle supplies it. Place it from `Plugin.php`:

```php
add_page_component('products.show', 'sidebar', [
    'plugin' => 'my-plugin',              // your slug
    'component' => 'StockNotes',          // the name your bundle registers
    'data' => ['title' => 'Notes'],       // passed to the component as props
    'permission' => 'view_products',      // optional
    'position' => 10,                     // lower renders first
]);

// Data computed per request, only for users who pass the permission check.
add_page_component('products.index', 'header', [
    'plugin' => 'my-plugin',
    'component' => 'MarginSummary',
    'permission' => 'view_reports',
    'data' => fn ($user) => ['margin' => my_plugin_margin($user->organization_id)],
]);
```

Pages and slots that render plugin components:

| Page | Slots |
|------|-------|
| `dashboard` | `header`, `before-stats`, `after-stats`, `before-content`, `widgets`, `after-content`, `footer` |
| `products.index` | `header`, `before-table`, `footer` |
| `products.show` | `header`, `actions`, `sidebar`, `tabs`, `footer` |
| `products.create`, `products.edit` | `header`, `before-form`, `after-form` |
| `orders.index` | `header`, `before-table`, `footer` |
| `orders.show` | `header`, `actions`, `sidebar`, `tabs`, `footer` |
| `purchase-orders.index` | `header`, `before-table`, `footer` |
| `purchase-orders.show` | `header`, `actions`, `sidebar`, `footer` |
| `purchase-orders.create`, `purchase-orders.edit`, `purchase-orders.receive` | `header`, `footer` |
| `suppliers.index` | `header`, `before-table`, `footer` |
| `suppliers.show`, `suppliers.create`, `suppliers.edit` | `header`, `footer` |
| `categories.index`, `locations.index` | `header`, `footer` |
| `customers.index`, `warehouses.index`, `stock-audits.index`, `cycle-counts.index`, `stock-transfers.index`, `returns.index`, `work-orders.index` | `header`, `footer` |
| `customers.show`, `warehouses.show`, `stock-audits.show`, `stock-transfers.show`, `returns.show`, `work-orders.show` | `header`, `actions`, `footer` |
| `settings.index` | `header`, `sections`, `footer` |

`actions` renders inside the page header's action bar, before the page's own buttons: keep it to small buttons or links. On the settings hub, each component in `sections` is one card in the grid of settings pages; render a `Card` linking to your settings page (the hub does not check the link, so give the placement the same `permission` as the page). Pages without plugin placements render exactly as before.

### Tabs on detail pages

The product and order detail pages take plugin tabs. A placement in their `tabs` slot adds a tab, with the page's own content kept as the first tab, "Overview". The tab bar appears only when at least one plugin tab is present for the current user, so the pages look exactly as before without plugins.

```php
add_page_component('products.show', 'tabs', [
    'plugin' => 'my-plugin',
    'component' => 'SupplierHistory',
    'label' => 'Supplier history',             // the tab's label
    'permission' => 'view_purchase_orders',    // users without it see no tab
    'data' => fn ($user) => ['productId' => request()->route('product')?->id],
    'position' => 10,                          // tab order
]);
```

The tab's component is only mounted while its tab is selected. The Overview content stays mounted, so switching back keeps its state. Tabs follow the ARIA tabs pattern and the left and right arrow keys move between them.

### Dashboard widgets

A widget is a titled card on the dashboard, below the built-in cards:

```php
register_dashboard_widget([
    'id' => 'my-plugin-margins',          // unique
    'title' => 'Gross margin',
    'title_key' => 'plugins.my-plugin.widgets.margin',   // optional, like a menu item's label_key
    'plugin' => 'my-plugin',
    'component' => 'MarginWidget',        // registered by your bundle
    'width' => 'half',                    // full, half, third or quarter
    'position' => 10,
    'permission' => 'view_reports',
    'data' => fn ($user) => ['margin' => my_plugin_margin($user->organization_id)],
]);
```

Widgets are gated like the dashboard's own figures. A widget the user may not see is left out of the page entirely rather than shown empty or as zero. Its closure never runs for them, so a query behind it costs nothing for users who cannot see it. The `data` becomes the component's props. A widget without a `component` is skipped.

### Plugin pages

`register_page()` gives your plugin a page of its own: a GET route that renders a `Plugin::` Inertia page, which your bundle supplies with `registerPage()`.

```php
register_page('plg.my-plugin.settings', 'Plugin::my-plugin/Settings', [
    'uri' => '/p/my-plugin/settings',     // default: the route name with dots as slashes
    'title' => 'My Plugin settings',      // passed to the page as the `title` prop
    'permission' => 'manage_plugins',     // users without it get 403
    'props' => fn ($request, $user) => [  // or a plain array
        'settings' => my_plugin_settings($user->organization_id),
    ],
    'middleware' => ['throttle:60,1'],    // extra middleware; `auth` and the web group always apply
]);
```

```js
// ui/src/main.js
plugin.registerPage('Settings', SettingsPage);   // becomes 'Plugin::my-plugin/Settings'
```

The route is added after all core routes and always requires sign-in: your `middleware` is added after `auth`, never instead of it. Link to it by name as usual (`route('plg.my-plugin.settings')`), for example from a menu item.

#### Route names and URIs

Everything a plugin routes lives in its own namespace, which core never uses:

| What | Names | URIs |
|------|-------|------|
| Pages and web routes (`register_page()`, `Route::middleware(['web', 'auth'])`) | `plg.{slug}.*` | `/p/{slug}/...` (writes under `/p/{slug}/actions/...`) |
| API routes (add `auth:sanctum` and `api.permission`) | `plg.{slug}.api.*` | `/api/v1/plugins/{slug}/...` |
| Inbound webhooks (outside the `web` group) | `plg.{slug}.webhooks.*` | `/webhooks/plugins/{slug}/...` |

Activation refuses a plugin whose page or route collides with a route the application (or another active plugin) already has, and says why, for example: *The page "plg.cycle-counts.sessions" uses /cycle-counts/sessions, inside /cycle-counts/, which the application route "cycle-counts.index" uses.* A page or route conflicts when it:

- reuses an existing route name, or uses another plugin's `plg.{slug}.` names;
- answers a method and URI an existing route answers (a page answers GET; a write route on your own page's URI is fine);
- lives under a first URI segment an existing route uses (such as `/cycle-counts/...` or `/reports/...`), unless it is inside your own `/p/{slug}/`, `/api/v1/plugins/{slug}/` or `/webhooks/plugins/{slug}/`; or
- (pages only) sits under a reserved prefix: `api`, `portal`, `install`, `graphql`, `mcp`, `webhooks`, `plugins`, `plugin-assets`, the sign-in and password pages, and similar.

A plugin that was activated before this check, and whose page later collides with a route core adds, keeps working without that page: the page is skipped and logged rather than taking over the core route.

If you cache routes (`php artisan route:cache`), rebuild the cache after activating or deactivating a plugin that registers pages. A cached route whose plugin is no longer active answers 404.

### The runtime bundle

The cPanel release and ZIP-uploaded plugins never run `npm`, so a plugin ships its UI already built. The bundle is an ES module built with Vite in library mode, with Vue left out: the app exposes its own Vue as `window.Inventoros.Vue`, and components must use that copy to share the app's reactivity.

While the plugin is active, Inventoros copies `dist/` to `public/plugin-assets/{slug}/` and lists the bundle in the `pluginAssets` page prop. When the manifest `version` changes, the copy is refreshed on the next page load. The browser imports the bundle once, from the same origin, which the app's Content Security Policy allows through `script-src 'self'`, and adds its stylesheets. This works on a full page load and after client-side navigation. Only static web files are published: `.js`, `.mjs`, `.css`, `.map`, `.json`, images, fonts and `.txt`. PHP files, dotfiles such as `.htaccess`, and symlinks are skipped, and `ui.entry`/`ui.styles` must point inside `dist/`.

The bundle's default export is called with an SDK scoped to the plugin:

```js
// ui/src/main.js
import StockNotes from './StockNotes.vue';
import MarginWidget from './MarginWidget.vue';
import SettingsPage from './SettingsPage.vue';
import Hint from './Hint.vue';

export default function setup(plugin) {
    // Implements add_page_component(..., ['plugin' => 'my-plugin', 'component' => 'StockNotes']).
    plugin.registerComponent('StockNotes', StockNotes);

    // Implements register_dashboard_widget([... 'component' => 'MarginWidget']).
    plugin.registerComponent('MarginWidget', MarginWidget);

    // Supplies the page for register_page(..., 'Plugin::my-plugin/Settings').
    plugin.registerPage('Settings', SettingsPage);

    // Adds a component to a slot from the browser alone ("<page>:<slot>").
    plugin.registerSlotComponent('dashboard:after-stats', Hint, { position: 50, props: { tone: 'info' } });
}
```

| SDK member | Purpose |
|------------|---------|
| `plugin.slug` | The plugin's slug. |
| `plugin.Vue` | The app's Vue (same as `window.Inventoros.Vue`). |
| `plugin.Inertia` | The app's `Head`, `Link`, `router`, `useForm` and `usePage`. Use these, not your own copy of `@inertiajs/vue3`, which would not see the current page. |
| `plugin.layouts.AppLayout` | The application layout (sidebar, header, flash messages), for plugin pages. |
| `plugin.ui` | Core building blocks: `PageHeader`, `Card`, `CardHeader`, `Button`, `Badge`; since `apiVersion` 2 also `Input`, `DataTable`, `StatTile`, `Modal`, `Checkbox`, `TextInput`, `InputLabel`, `InputError`, `PrimaryButton`, `SecondaryButton`, `DangerButton`, and the camera `BarcodeScanner` and `BarcodeScannerModal` (loaded on first use). |
| `plugin.i18n` | Since `apiVersion` 2. `addMessages(locale, messages)`, `t(key, params)`, `te(key)` and the read-only `locale`, on the app's own translations and scoped to the plugin. See [Translations](#translations). |
| `plugin.registerComponent(name, component)` | Provide the component for a server placement (`add_page_component()`) or a dashboard widget. |
| `plugin.registerPage(page, component)` | Provide the page component rendered by `register_page(..., 'Plugin::{slug}/{page}')`. |
| `plugin.registerSlotComponent(slot, component, { position, props, label })` | Render a component in a slot without a server placement. `slot` is `"<page>:<slot>"`, for example `"products.show:sidebar"`. For a `tabs` slot, `label` is the tab label. |

The same members are available globally on `window.Inventoros`, where the register functions take the slug explicitly: `registerComponent(slug, name, component)`, `registerSlotComponent(slot, component, options)` and `registerPage('Plugin::slug/Page', component)`. `window.Inventoros.plugin(slug)` returns the scoped SDK. `window.Inventoros.i18n` is read-only (`t`, `te` and `locale` on the app's own keys). `window.Inventoros.apiVersion` is `2` on installs that offer the members marked "since `apiVersion` 2"; check it, or that a member exists (`plugin.ui.Input ?? fallback`), when a plugin supports older installs.

#### Translations

A plugin's strings live under `plugins.{slug}` in the app's translations. `addMessages()` can only write there, and `t()` and `te()` read keys relative to it, so a plugin cannot change the app's strings or another plugin's. Ship a JSON file per locale and add each one; untranslated keys fall back to English:

```js
// ui/src/main.js
import en from './locales/en.json';
import fr from './locales/fr.json';

export default function setup(plugin) {
    plugin.i18n.addMessages('en', en);   // { "settings": { "title": "My Plugin" } }
    plugin.i18n.addMessages('fr', fr);
}

// In a component: plugin.i18n.t('settings.title') reads plugins.my-plugin.settings.title
```

Messages may contain strings and nested objects only.

A plugin page, using the shared layout and helpers:

```vue
<!-- ui/src/SettingsPage.vue -->
<script setup>
const {
    layouts: { AppLayout },
    ui: { PageHeader, Card },
    Inertia: { Head, useForm },
} = window.Inventoros;

const props = defineProps({ title: String, settings: Object });
const form = useForm({ ...props.settings });
</script>

<template>
    <Head :title="title" />
    <AppLayout>
        <div>
            <PageHeader :title="title" />
            <Card class="my-plugin-card">...</Card>
        </div>
    </AppLayout>
</template>
```

Wrap the page body in a single root element inside `AppLayout`, as core pages do.

Server placements and widgets carry a server-enforced `permission`. `registerSlotComponent()` components are browser-only and render for everyone who can see the page, so gate sensitive UI with a server placement instead.

Styling: the app's Tailwind build does not scan plugins, so Tailwind classes that the app itself does not use will have no CSS. Put styles in `<style scoped>` blocks (they are extracted to your CSS file) and use the design tokens, which are HSL triplets that follow the light and dark theme:

```css
.my-card {
    background: hsl(var(--surface-raised));
    border: 1px solid hsl(var(--border-subtle));
    color: hsl(var(--text-primary));
}
```

Available tokens include `--surface-canvas`, `--surface-base`, `--surface-raised`, `--surface-sunken`, `--border-subtle`, `--border-strong`, `--text-primary`, `--text-secondary`, `--text-tertiary`, `--accent`, `--accent-soft`, `--status-success`, `--status-warning`, `--status-danger` and `--ring`.

### Build-time components (source installs only)

Installs that build the frontend themselves also pick up `plugins/{slug}/resources/js/Components/{Name}.vue` and `plugins/{slug}/resources/js/Pages/**/*.vue` at `npm run build` time. That route does not work for uploaded plugins or the cPanel release, so prefer the runtime bundle.

## MCP tools

A plugin can add tools to the Inventoros MCP server (`/mcp`, see the MCP server docs) from its main file:

```php
register_mcp_tool(string $slug, Laravel\Mcp\Server\Tool|string $tool, string|array $permission): void
```

```php
register_mcp_tool('stock-insights', \Inventoros\Plugins\StockInsights\Mcp\SummaryTool::class, 'stock-insights.view');
```

The tool is an ordinary `laravel/mcp` tool class from your plugin (`schema()`, `handle(Request $request)`, attributes such as `#[IsReadOnly]`), with an explicit snake_case `$name` that starts with your slug in snake case: plugin `stock-insights` registers `stock_insights_summary`. Registering a name without that prefix, one that a core tool uses, a class that is not a `Tool`, or no permission throws `InvalidArgumentException`.

Core, not your tool, decides who may use it. `$permission` is a permission name (core or one you registered with `register_permission()`) or a list meaning "any of". The tool is listed in `tools/list` and runs on `tools/call` only when the user holds one of them **and** the bearer token's abilities allow it (a token limited to `view_products` cannot reach a tool gated on `stock-insights.view`). Everyone else neither sees nor can call it. Inside `handle()`, `$request->user()` is the acting user; scope every query to `$request->user()->organization_id`, and follow the core tools' conventions: integer quantities, decimal-string money, `Response::error()` for failures, and an explicit confirmation step in the description for anything that writes.

Tools are registered while the plugin is active. On Inventoros versions without this, check `function_exists('register_mcp_tool')`.

## Outbound webhook events

Users subscribe webhooks to events in Settings > Webhooks. A plugin can add its own events and send them through core's delivery, which signs every payload (`X-Webhook-Signature`), retries failures with back-off, refuses private and loopback targets, and logs each delivery:

```php
register_webhook_event(string $event, string $description, ?string $group = null): void
dispatch_webhook_event(string $event, array $data, Organization|int $organization): void
```

```php
// Plugin.php
register_webhook_event('cycle-counts.session_completed', 'When a count session is completed', 'Cycle counts');

// When it happens (inside or outside a transaction):
dispatch_webhook_event('cycle-counts.session_completed', [
    'session_id' => $session->id,
    'counted' => $session->counted_lines,
], $session->organization_id);
```

- The name is `{plugin-slug}.{event}` (lowercase letters, digits and underscores after the dot). Core events begin with a core resource (`product.`, `order.`, ...), which a plugin cannot use. Anything else throws `InvalidArgumentException`.
- Registered events appear in the webhook event picker under `$group` (by default your slug as a title, "Cycle Counts") and are accepted in webhook subscriptions over the web and REST API.
- `dispatch_webhook_event()` sends to the organization's active webhooks subscribed to that event, and only after the surrounding transaction commits; nothing is sent if it rolls back. The payload has the same envelope as core events: `id`, `event`, `timestamp`, `organization_id` and your `data`. Never put secrets or another organization's data in it.
- Dispatching an event that is not registered throws. A deactivated plugin's events are no longer offered and nothing sends them; existing subscriptions keep the name and resume when it is reactivated.

On Inventoros versions without this, check `function_exists('register_webhook_event')`.

## Building and packaging a plugin

1. Copy `plugins/hello-world/ui/vite.config.js` and `ui/package.json` into your plugin's `ui/` folder and put your entry in `ui/src/main.js`. The config maps every `import ... from 'vue'` to `window.Inventoros.Vue`, writes `dist/plugin.js` and `dist/plugin.css`, and ignores the app's PostCSS setup.
2. Build:

   ```bash
   cd plugins/my-plugin/ui
   npm install
   npm run build
   ```

   Inside the Inventoros repository you can skip `npm install` and run `npx vite build --config plugins/my-plugin/ui/vite.config.js` from the root; `npm run build:plugin:hello-world` does this for the example.
3. Point `ui.entry` (and `ui.styles`) in `plugin.json` at the built files, relative to `dist/`.
4. Bump `version` in `plugin.json` whenever the bundle changes, so browsers fetch the new file.
5. Zip the plugin folder so the archive has a single top-level folder named after the slug, without `ui/node_modules`:

   ```bash
   cd plugins
   zip -r my-plugin.zip my-plugin -x 'my-plugin/ui/node_modules/*'
   ```

6. Optionally sign it for installs that require signed plugins: `php artisan update:sign my-plugin.zip` writes `my-plugin.zip.sig`; paste its contents into the upload form's signature field.

Every Inventoros release attaches `hello-world-plugin.zip`, built exactly this way.

## Publishing to the marketplace

Anyone can publish a plugin on [inventoros.com/marketplace](https://inventoros.com/marketplace):

1. Create an inventoros.com account and apply to become a developer from your account page. An Inventoros admin approves developer accounts.
2. Package your plugin as described in [Building and packaging a plugin](#building-and-packaging-a-plugin): a ZIP with a single top-level folder named after the slug, containing `plugin.json`.
3. Submit it at [inventoros.com/developer](https://inventoros.com/developer) with its listing details and the ZIP. The marketplace checks that it is a valid ZIP within the size limit, that `plugin.json` matches the slug and version you entered, and that it has no path traversal, symlinks or PHP outside the expected places.
4. The submission waits in a review queue. When it is approved, the marketplace signs the package with its key and publishes it; if it is rejected you get an email with the reason.

**Versioning.** To release a new version, bump `version` in `plugin.json` (use semantic versions such as `1.4.0`), rebuild and re-zip, and submit it as a new version of your plugin. Installs see it as an available update once it is approved. Raise `requires` when the plugin starts depending on a newer Inventoros.

**Pricing.** Third-party plugins are listed as free for now. Selling third-party plugins (and developer payouts) is not supported yet.

## Licences for paid plugins

The marketplace only serves a paid plugin's package to an organization whose connected inventoros.com account owns it. At runtime, a paid plugin checks its licence with `plugin_licence()`:

```php
plugin_licence(string $slug, Organization|int|null $organization = null): App\Services\Marketplace\PluginLicence
```

```php
$licence = plugin_licence('insights');                 // the signed-in user's organization
$licence = plugin_licence('insights', $organizationId); // queued jobs, commands, schedules

if (! $licence->allowsWrites()) {
    // Open read-only: show a notice with a link to renew, refuse writes,
    // skip syncs. Keep the plugin's data.
}
```

`PluginLicence` carries `status`, `reason`, `expiresAt`, `graceUntil`, `inGrace` and `checkedAt`, plus `isValid()`, `allowsWrites()` (the same) and `toArray()`:

| `status` | `reason` | Meaning |
|----------|----------|---------|
| `valid` | `active` | A signed entitlement covers today. |
| `valid` | `offline_grace` | The entitlement ran out while the marketplace could not be reached; it keeps counting until `graceUntil` (14 days after `expiresAt` by default). `inGrace` is true. |
| `expired` | `expired` | The entitlement ran out (a cancelled or lapsed subscription, or offline past the grace period). |
| `missing` | `not_connected`, `not_owned` | The organization has no marketplace connection, or its account does not own the plugin. |
| `unknown` | `not_checked`, `no_public_key`, `invalid_signature`, `install_mismatch`, `no_organization`, `error` | Nothing verifiable yet. |

How it works:

- When an organization connects its inventoros.com account, and daily after that (`marketplace:refresh-entitlements`, scheduled), Inventoros fetches `GET /api/v1/marketplace/entitlements?install_id=...` with the organization's marketplace token. Each owned paid plugin comes back with an `expires_at` and an Ed25519 signature over `inventoros-marketplace-entitlement-v1`, the install id, the slug and `expires_at`, one per line.
- The install id is a random id generated once per installation, plus the organization id, so an entitlement copied to another installation or organization does not verify.
- The document is stored per organization and every entry is verified against `INVENTOROS_MARKETPLACE_PUBLIC_KEY` each time it is read: editing the stored row grants nothing. A refresh whose document does not verify is rejected and the previous one is kept.
- `plugin_licence()` never contacts the marketplace. When the stored document is older than `INVENTOROS_MARKETPLACE_ENTITLEMENT_REFRESH_HOURS` (24), it queues a refresh, at most once an hour per organization.
- `INVENTOROS_MARKETPLACE_ENTITLEMENT_GRACE_DAYS` (14) sets the offline grace period.

**Fail safe.** The check never throws and never blocks a request. Only `valid` unlocks paid features; in every other state the plugin degrades to read-only. A plugin must never, in any state, block or slow a core page, change or hide core records (stock, orders, products), delete its own data, or call the marketplace itself on each request. The check is ordinary readable PHP: the protection is the signed entitlement and the licence terms, not hidden code.

On Inventoros versions without it, check `function_exists('plugin_licence')`.

## Examples

### Low-stock notifier

```php
<?php
// plugins/low-stock-notifier/Plugin.php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Email the warehouse when a product first drops to its minimum stock.
add_action('low_stock_alert', function ($product) {
    try {
        Mail::raw(
            "{$product->name} ({$product->sku}) is down to {$product->stock}.",
            fn ($message) => $message->to('warehouse@example.com')->subject('Low stock: '.$product->sku)
        );
    } catch (\Throwable $e) {
        Log::error('Low stock notifier failed', ['error' => $e->getMessage()]);
    }
});

// Flag low-stock products wherever product names are displayed.
add_filter('product_display_name', function ($name, $product) {
    return $product->isLowStock() ? $name.' (low stock)' : $name;
});
```

### Custom pricing

```php
<?php
// plugins/dynamic-pricing/Plugin.php

use Illuminate\Support\Facades\Log;

// Show volume discounts on well-stocked products. Only the displayed price
// changes; the stored price and order pricing do not.
add_filter('product_price_display', function ($price, $product) {
    if ($price === null) {
        return null;
    }

    $discount = match (true) {
        $product->stock > 100 => 0.10,
        $product->stock > 50 => 0.05,
        default => 0.0,
    };

    return round($price * (1 - $discount), 2);
});

// Log price changes made from the product form. The product still holds its
// old price when product_before_update fires.
add_action('product_before_update', function ($product, $data) {
    if (array_key_exists('price', $data) && (float) $data['price'] !== (float) $product->price) {
        Log::info('Price changed', [
            'sku' => $product->sku,
            'old_price' => (float) $product->price,
            'new_price' => (float) $data['price'],
        ]);
    }
});

// Add a handling fee to every order total. $total is a 2-decimal string;
// Money keeps the arithmetic exact.
add_filter('order_total_calculation', function ($total, $order) {
    return \App\Support\Money::add($total, '2.50');
});
```

### Search by notes

```php
<?php
// plugins/search-notes/Plugin.php

add_filter('product_search_query', function ($query, $term) {
    return $query->orWhere('notes', 'like', '%'.$term.'%');
});
```

### Product view analytics

```php
<?php
// plugins/analytics/hooks/activate.php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasTable('plugin_analytics_views')) {
    Schema::create('plugin_analytics_views', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        $table->timestamp('viewed_at');
    });
}
```

```php
<?php
// plugins/analytics/Plugin.php

use Illuminate\Support\Facades\DB;

add_action('product_viewed', function ($product, $user) {
    DB::table('plugin_analytics_views')->insert([
        'organization_id' => $product->organization_id,
        'product_id' => $product->id,
        'viewed_at' => now(),
    ]);
});

add_filter('dashboard_stats_data', function ($stats, $user) {
    $stats['productViewsToday'] = DB::table('plugin_analytics_views')
        ->where('organization_id', $user->organization_id)
        ->where('viewed_at', '>=', today())
        ->count();

    return $stats;
});
```

```php
<?php
// plugins/analytics/hooks/uninstall.php

use Illuminate\Support\Facades\Schema;

Schema::dropIfExists('plugin_analytics_views');
```

## Best practices

- **Always return from filters.** A filter that returns nothing replaces the value with `null`.
- **Scope data by organization.** Inventoros is multi-tenant: store `organization_id` on every row you create and filter by it when you read.
- **Make `activate.php` idempotent** (`Schema::hasTable` before `Schema::create`) and clean up everything in `uninstall.php`.
- **Catch your own errors** in hooks that talk to other systems, so a failing mail server does not break product saves.
- **Namespace your PHP classes** (`namespace MyPlugin;`) and prefix custom hook names and tables with your slug. Name routes `plg.{slug}.*` under `/p/{slug}/` (see [Route names and URIs](#route-names-and-uris)).
- **Change stock only through `StockAdjustment::adjust()`** (or `StockAuditService`, `OrderService` and the other core services). It keeps the location bins in step with the on-hand total: pass `locationId` to book one bin; without it a decrease drains the bins in the order fulfilment uses (warehouse priority, then the primary location, then the fullest bin) and an increase lands in the primary bin. Pass `syncBins: false` only if you move the bins yourself with `ProductLocationStockService`.
- **Return a registration closure from `Plugin.php`**, and keep named functions and classes in files you `require_once` (see [The main file](#the-main-file)).
- **Declare `requires`** with the lowest Inventoros version you tested against.

## Security notes

A plugin runs PHP inside the application with full access to the database and filesystem. Install only plugins you trust.

- Uploads are disabled until `INVENTOROS_ALLOW_PLUGIN_UPLOADS=true` is set, because an admin who can upload a plugin can run code on the server.
- Plugins are shared by every organization on an installation, so only users with Manage Plugins in the plugin administrator organization can install, update, upload, activate, deactivate or delete them. That is the organization set in `INVENTOROS_PLUGIN_ADMIN_ORG`, or the first organization (the one the installer created) when it is unset. Admins of other organizations can browse plugins only.
- Marketplace installs are allowed with uploads off because every marketplace package must carry a valid Ed25519 signature from the marketplace key configured in `config/marketplace.php`, and match the sha256 in the catalog. The signature covers the plugin's slug, version and sha256 together, the package's `plugin.json` must declare that signed version, and an update must be newer than the installed version, so an older signed package cannot be replayed as an update. With no key configured they are refused.
- `INVENTOROS_PLUGIN_SIGNATURE_REQUIRED=true` with `INVENTOROS_PLUGIN_PUBLIC_KEY` accepts only ZIPs signed with your key.
- Uploaded ZIPs are checked for path traversal, entry count and size before extraction.
- Query filters (`product_list_query`, `supplier_list_query`) have the organization scope re-applied after they run, and `product_search_query` only sees the search group.

## Debugging

- Plugin load, activation and hook failures are logged to `storage/logs/laravel.log` with the plugin slug.
- A plugin whose main file throws at boot is skipped for that request (and logged) rather than taking the application down.
- A runtime bundle that fails to import is reported in the browser console as `[Inventoros] Plugin "{slug}" UI failed to load.`
- Check `window.Inventoros` and the `pluginAssets` prop (Vue devtools, or `JSON.parse(document.querySelector('script[data-page]').textContent).props.pluginAssets`) to see which bundles the page is loading.
