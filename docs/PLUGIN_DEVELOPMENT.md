# Inventoros Plugin Development Guide

Plugins extend Inventoros without changing core files. A plugin is a folder under `/plugins` with a manifest, a PHP file that registers WordPress-style actions and filters, optional lifecycle files, and an optional pre-built browser bundle for its UI.

Everything in this guide is backed by code and tests: the hook tables are checked against `app/Services/HookRegistry.php`, which is itself checked against every hook the application fires (`tests/Feature/PluginHookDocumentationTest.php`). The `plugins/hello-world` plugin is a working example of every part.

## Table of contents

1. [Plugin structure](#plugin-structure)
2. [The manifest (plugin.json)](#the-manifest-pluginjson)
3. [Installing, activating and removing](#installing-activating-and-removing)
4. [Actions and filters](#actions-and-filters)
5. [Lifecycle files](#lifecycle-files)
6. [Action reference](#action-reference)
7. [Filter reference](#filter-reference)
8. [Plugin UI](#plugin-ui)
9. [Building and packaging a plugin](#building-and-packaging-a-plugin)
10. [Examples](#examples)
11. [Best practices](#best-practices)
12. [Security notes](#security-notes)
13. [Debugging](#debugging)

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

## The manifest (plugin.json)

```json
{
    "name": "My Plugin",
    "description": "What the plugin does",
    "version": "1.0.0",
    "author": "Your Name",
    "author_url": "https://example.com",
    "requires": "1.0.8",
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

- **Install** by copying the folder into `/plugins`, or upload a ZIP from **Admin > Plugins**. Uploads are off by default (see [Security notes](#security-notes)). The ZIP must contain exactly one top-level folder, which becomes the slug.
- **Activate** from the Plugins page. In order, Inventoros:
  1. checks `requires` and `requires_php`,
  2. validates the `ui` block,
  3. loads `main_file`,
  4. runs `hooks/activate.php`,
  5. publishes `dist/` to `public/plugins/{slug}/`,
  6. fires `plugin_activated` and `plugin_activated_{slug}`,
  7. marks the plugin active.

  If any step throws, the plugin stays inactive, its published files are removed and the Plugins page shows the error.

  Over SSH the same lifecycle runs with `php artisan plugin:activate {slug}` and `php artisan plugin:deactivate {slug}`.
- **Deactivate**: fires `plugin_deactivated` and `plugin_deactivated_{slug}`, runs `hooks/deactivate.php`, marks the plugin inactive and removes `public/plugins/{slug}/`. The plugin is deactivated even if its own code throws; the page then shows a warning.
- **Delete**: fires `plugin_uninstalling` and `plugin_uninstalling_{slug}`, deactivates the plugin, runs `hooks/uninstall.php` (whether or not the plugin was active), then removes its published files, its database record and its folder. The files are removed even if the plugin's cleanup throws; the page then shows a warning.

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

"Web form" and "web UI" hooks fire only from the Inertia screens; the REST, GraphQL and MCP surfaces do not fire them. `product_created` fires on every surface.

| Action | Arguments | When |
|--------|-----------|------|
| `plugin_loaded` | `$slug`, `$manifest` | After a plugin's main file is loaded (at boot for every active plugin, and on activation). |
| `plugin_activated` | `$slug` | Any plugin is activated, after its `hooks/activate.php` ran. Throwing aborts the activation. |
| `plugin_activated_{slug}` | none | A specific plugin is activated, for example `plugin_activated_my-plugin`. |
| `plugin_deactivated` | `$slug` | Any plugin is deactivated, before its `hooks/deactivate.php` runs. |
| `plugin_deactivated_{slug}` | none | A specific plugin is deactivated. |
| `plugin_uninstalling` | `$slug` | Any plugin is about to be deleted, before its `hooks/uninstall.php` runs. |
| `plugin_uninstalling_{slug}` | none | A specific plugin is about to be deleted. |
| `product_created` | `$product`, `$user` | A product is created on any surface (web, REST, GraphQL, MCP, import). `$user` may be null. |
| `product_before_create` | `$validated_data`, `$request` | Before a product is created from the web form. |
| `product_after_create` | `$product`, `$request` | After a product is created from the web form. |
| `product_before_update` | `$product`, `$validated_data`, `$request` | Before a product is updated from the web form. `$product` still holds the old values. |
| `product_updated` | `$product`, `$user` | After a product is updated from the web form. |
| `product_after_update` | `$product`, `$request` | After a product is updated from the web form. |
| `product_before_delete` | `$product`, `$request` | Before a product is deleted from the web UI. |
| `product_deleted` | `$product`, `$user` | After a product is deleted from the web UI. |
| `product_after_delete` | `$product`, `$request` | After a product is deleted from the web UI. |
| `product_viewed` | `$product`, `$user` | The product detail page is viewed. |
| `product_list_viewed` | `$products`, `$user` | The product list page is viewed (`$products` is the paginator). |
| `stock_adjusted` | `$stock_adjustment`, `$product` | A stock adjustment is committed. `$product` is null for a variant adjustment. |
| `low_stock_alert` | `$product` | A product's stock drops to or below its minimum. |
| `out_of_stock_alert` | `$product` | A product's stock reaches zero. |
| `warehouse_low_stock_alert` | `$product`, `$warehouse`, `$on_hand` | A product's on-hand quantity in one warehouse drops to or below its minimum stock level for that warehouse. |
| `order_created` | `$order`, `$user` | An order and all its items are created. |
| `order_updated` | `$order`, `$user` | An order is saved. |
| `order_status_changed` | `$order`, `$old_status`, `$new_status`, `$user` | An order's status changes. |
| `order_approved` | `$order`, `$user` | An order is approved. |
| `order_rejected` | `$order`, `$user` | An order is rejected. |
| `purchase_order_created` | `$purchase_order`, `$user` | A purchase order is created. |
| `purchase_order_received` | `$purchase_order`, `$user` | A purchase order becomes fully received. |
| `purchase_order_cancelled` | `$purchase_order`, `$user` | A purchase order is cancelled. |
| `customer_created` | `$customer`, `$user` | A customer is created on any surface. |
| `customer_updated` | `$customer`, `$user` | A customer is updated on any surface. |
| `customer_deleted` | `$customer`, `$user` | A customer is deleted on any surface. |
| `return_created` | `$return_order`, `$user` | A return is created. |
| `return_received` | `$return_order`, `$user` | A return is marked received. |
| `transfer_created` | `$transfer`, `$user` | A stock transfer is created. |
| `transfer_completed` | `$transfer`, `$user` | A stock transfer is completed. |
| `stock_audit_completed` | `$stock_audit`, `$user` | A stock audit is completed. |
| `work_order_completed` | `$work_order`, `$user` | A work order is completed. |
| `supplier_created` | `$supplier`, `$user` | A supplier is created from the web UI. |
| `supplier_updated` | `$supplier`, `$user` | A supplier is updated from the web UI. |
| `supplier_before_delete` | `$supplier`, `$user` | Before a supplier is deleted from the web UI. |
| `supplier_deleted` | `$supplier`, `$user` | After a supplier is deleted from the web UI. |
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
| `order_total_calculation` | `$total`, `$order` | The computed order total when an order is created or edited, after line items, discounts, tax and shipping. Return a non-negative number. |
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

## Plugin UI

Every server-side UI registration takes an optional `permission`: a permission name from `app/Enums/Permission.php`, or a list of names meaning "any of". It is enforced on the server. A user who lacks it never receives the entry, and an entry with a permission is hidden from guests. Where an entry takes `data` or `props`, you can pass a closure instead of an array. The closure only runs for users who pass the permission check, so a figure they may not see is never computed.

### Menu items

```php
register_menu_item([
    'label' => 'My Plugin',
    'route' => 'my-plugin.settings',   // or 'url' => 'https://...'
    'permission' => 'manage_plugins',  // optional
    'position' => 100,
]);
```

Items whose route does not exist are dropped. Submenu entries (`'submenu' => [[...], ...]`) are filtered by their own `permission` the same way.

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
| `products.show` | `header`, `sidebar`, `footer` |
| `products.create`, `products.edit` | `header`, `before-form`, `after-form` |
| `orders.index` | `header`, `before-table`, `footer` |
| `orders.show` | `header`, `sidebar`, `footer` |
| `purchase-orders.index` | `header`, `before-table`, `footer` |
| `purchase-orders.show` | `header`, `sidebar`, `footer` |
| `purchase-orders.create`, `purchase-orders.edit`, `purchase-orders.receive` | `header`, `footer` |
| `suppliers.index` | `header`, `before-table`, `footer` |
| `suppliers.show`, `suppliers.create`, `suppliers.edit` | `header`, `footer` |
| `categories.index`, `locations.index` | `header`, `footer` |

### Dashboard widgets

A widget is a titled card on the dashboard, below the built-in cards:

```php
register_dashboard_widget([
    'id' => 'my-plugin-margins',          // unique
    'title' => 'Gross margin',
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
register_page('my-plugin.settings', 'Plugin::my-plugin/Settings', [
    'uri' => '/my-plugin/settings',       // default: the route name with dots as slashes
    'title' => 'My Plugin settings',      // passed to the page as the `title` prop
    'permission' => 'manage_plugins',     // users without it get 403
    'props' => fn ($request, $user) => [  // or a plain array
        'settings' => my_plugin_settings($user->organization_id),
    ],
    'middleware' => ['auth'],             // default; the web middleware group always applies
]);
```

```js
// ui/src/main.js
plugin.registerPage('Settings', SettingsPage);   // becomes 'Plugin::my-plugin/Settings'
```

The route is added after all core routes. A page cannot reuse a route name that already exists (it is skipped and logged), so a plugin cannot take over a core route or link. Link to it by name as usual (`route('my-plugin.settings')`), for example from a menu item.

If you cache routes (`php artisan route:cache`), rebuild the cache after activating or deactivating a plugin that registers pages. A cached route whose plugin is no longer active answers 404.

### The runtime bundle

The cPanel release and ZIP-uploaded plugins never run `npm`, so a plugin ships its UI already built. The bundle is an ES module built with Vite in library mode, with Vue left out: the app exposes its own Vue as `window.Inventoros.Vue`, and components must use that copy to share the app's reactivity.

While the plugin is active, Inventoros copies `dist/` to `public/plugins/{slug}/` and lists the bundle in the `pluginAssets` page prop. When the manifest `version` changes, the copy is refreshed on the next page load. The browser imports the bundle once, from the same origin, which the app's Content Security Policy allows through `script-src 'self'`, and adds its stylesheets. This works on a full page load and after client-side navigation. Only static web files are published: `.js`, `.mjs`, `.css`, `.map`, `.json`, images, fonts and `.txt`. PHP files, dotfiles such as `.htaccess`, and symlinks are skipped, and `ui.entry`/`ui.styles` must point inside `dist/`.

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
| `plugin.ui` | Core building blocks: `PageHeader`, `Card`, `CardHeader`, `Button`, `Badge`. |
| `plugin.registerComponent(name, component)` | Provide the component for a server placement (`add_page_component()`) or a dashboard widget. |
| `plugin.registerPage(page, component)` | Provide the page component rendered by `register_page(..., 'Plugin::{slug}/{page}')`. |
| `plugin.registerSlotComponent(slot, component, { position, props })` | Render a component in a slot without a server placement. `slot` is `"<page>:<slot>"`, for example `"products.show:sidebar"`. |

The same members are available globally on `window.Inventoros`, where the register functions take the slug explicitly: `registerComponent(slug, name, component)`, `registerSlotComponent(slot, component, options)` and `registerPage('Plugin::slug/Page', component)`. `window.Inventoros.plugin(slug)` returns the scoped SDK.

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

// Add a handling fee to every order total.
add_filter('order_total_calculation', function ($total, $order) {
    return round((float) $total + 2.50, 2);
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
- **Namespace your PHP classes** (`namespace MyPlugin;`) and prefix custom hook names and tables with your slug.
- **Declare `requires`** with the lowest Inventoros version you tested against.

## Security notes

A plugin runs PHP inside the application with full access to the database and filesystem. Install only plugins you trust.

- Uploads are disabled until `INVENTOROS_ALLOW_PLUGIN_UPLOADS=true` is set, because an admin who can upload a plugin can run code on the server.
- `INVENTOROS_PLUGIN_SIGNATURE_REQUIRED=true` with `INVENTOROS_PLUGIN_PUBLIC_KEY` accepts only ZIPs signed with your key.
- Uploaded ZIPs are checked for path traversal, entry count and size before extraction.
- Query filters (`product_list_query`, `supplier_list_query`) have the organization scope re-applied after they run, and `product_search_query` only sees the search group.

## Debugging

- Plugin load, activation and hook failures are logged to `storage/logs/laravel.log` with the plugin slug.
- A plugin whose main file throws at boot is skipped for that request (and logged) rather than taking the application down.
- A runtime bundle that fails to import is reported in the browser console as `[Inventoros] Plugin "{slug}" UI failed to load.`
- Check `window.Inventoros` and the `pluginAssets` prop (Vue devtools, or `JSON.parse(document.querySelector('script[data-page]').textContent).props.pluginAssets`) to see which bundles the page is loading.
