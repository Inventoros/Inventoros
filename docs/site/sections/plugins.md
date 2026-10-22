Inventoros has a WordPress-style plugin system for extending the application without modifying core files. Plugins use actions to run code at specific points and filters to change data before it is used or displayed, and can add their own UI to existing pages.

This is a condensed overview. The full guide, with every available action and filter, lives in the repository: https://github.com/Inventoros/Inventoros/blob/main/docs/PLUGIN_DEVELOPMENT.md

Each release also attaches `hello-world-plugin.zip`, a small working example you can upload and read. It is also listed on the [marketplace](https://inventoros.com/marketplace), so you can install it from **Plugins > Marketplace**.

## Key concepts

- Plugins live in the `/plugins` directory at the project root, one folder per plugin. The folder name is the plugin's slug.
- Actions let you execute code at specific points in the application.
- Filters let you modify data before it is used or displayed.
- Lifecycle files run once on activation, deactivation and deletion.
- A plugin's UI ships as a pre-built bundle, so it works on installs that never run npm.
- All plugin data must respect multi-tenancy. Always scope queries by `organization_id`.

## Plugin structure

```text
my-plugin/
  plugin.json          # Required: manifest
  Plugin.php           # Required: loaded on every request while active
  hooks/               # Optional: lifecycle files
    activate.php       # Runs once on activation
    deactivate.php     # Runs once on deactivation
    uninstall.php      # Runs once on deletion
  ui/                  # Optional: source of the browser bundle
  dist/                # Optional: the pre-built browser bundle
  README.md
```

`Plugin.php` runs once for each application Inventoros boots, including the extra one `php artisan route:cache` boots in the same process. Return a registration closure from it (`return function (string $slug, array $manifest): void { ... };`) and keep named functions and classes in files you `require_once`, so the plugin's pages are always in the route cache.

### The plugin.json manifest

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
    "ui": { "entry": "plugin.js", "styles": ["plugin.css"] }
}
```

`requires` is the minimum Inventoros version and `requires_php` the minimum PHP version. Inventoros refuses to upload or activate a plugin whose requirements are not met, and says why. `main_file` defaults to `Plugin.php`. `ui` points at the built bundle: the entry must be a `.js` or `.mjs` file and the styles `.css` files, all inside `dist/`.

### Lifecycle files

`hooks/activate.php` runs after the plugin loads on activation; create tables and defaults there. If it throws, the plugin stays inactive and the Plugins page shows the error. `hooks/deactivate.php` runs on deactivation and `hooks/uninstall.php` when the plugin is deleted. If either throws, the plugin is still deactivated or removed and the page shows a warning.

## Hooks and filters

Actions run code:

```php
add_action('product_created', function ($product, $user) {
    // Send a notification, sync to an external system, log an event.
});
```

Filters receive a value and must return it:

```php
add_filter('product_price_display', function ($price, $product) {
    return $price === null ? null : round($price * 0.9, 2); // Show a 10% discount.
});
```

Priority sets the order; lower numbers run first (default 10). Commonly used actions include `product_created`, `product_updated`, `order_created`, `order_status_changed`, `low_stock_alert` and `dashboard_stats`. Common filters include `product_display_name`, `product_price_display`, `product_search_query`, `order_total_calculation` and `dashboard_stats_data`.

The created, updated and deleted hooks of products, variants, orders, purchase orders and customers, and `stock_changed`, fire from every surface (web, bulk actions, REST, GraphQL, MCP, imports and commands), once per record per transaction and only after it commits. Use them for integrations that must see every change:

```php
add_action('stock_changed', function ($product, $variant, $change) {
    // $change['before'], $change['after'], and $change['locations'][$locationId]['before'|'after'].
    // $change['reserved']['before'|'after'] when stock reservations changed.
});
```

## Reservations

A plugin that promises stock (a rental booking, a pick wave, a kit run, units published to a sales channel) holds it with a stock reservation, so nothing else in Inventoros sells, transfers out of a held bin, allocates or writes off those units. Available to promise is on hand less the active holds, checked under the same lock as every stock movement. With no holds, available equals on hand.

```php
use App\Services\StockReservationService;

$reservations = app(StockReservationService::class);

// Hold 3 units for booking B-1042 until the hire starts (throws when fewer are available).
$reservations->reserve($product, null, 3, 'my-rental', 'B-1042', expiresAt: $booking->starts_at);

// Hold one serial, or units in one bin.
$reservations->reserve($camera, null, 1, 'my-rental', 'B-1043', serial: $serial);
$reservations->reserve($product, null, 2, 'my-rental', 'B-1044', locationId: $bin->id);

$reservations->available($product);         // on hand less holds
$reservations->release('my-rental', 'B-1042');
```

An order that fulfils the hold carries it, and is checked against available plus that hold, which it consumes exactly once:

```php
app(OrderService::class)->create([
    'customer_name' => 'Pat',
    'items' => [['product_id' => $product->id, 'quantity' => 3]],
    'reservation_owner' => 'my-rental',
    'reservation_reference' => 'B-1042',
], $actor);
```

- A product hold limits the product total; a bin hold also limits that bin; variant, serial and batch holds limit those records.
- A retried `reserve()` with the same owner, reference and target holds once. Expired holds stop counting at once and are released by a scheduled command.
- Recounts and damage or loss write-offs are never refused because stock is promised. When one leaves fewer units than are held, `reservation_shortfall` fires so the owner can react.
- `stock_reserved`, `stock_reservation_released` and `stock_reservation_consumed` fire after commit. `product_available_quantity` changes the available figure shown on product pages and in the API, never a check.
- A plugin's holds are released when it is uninstalled. Admins see active holds under Inventory, Reservations, and can release one there.

The plugin development guide has the full reference.

## Adding UI

Place a component from `Plugin.php`:

```php
add_page_component('dashboard', 'header', [
    'plugin' => 'my-plugin',
    'component' => 'Banner',
]);
```

Build the component into an ES module with Vite in library mode (copy `ui/vite.config.js` from the hello-world plugin, which uses the app's own Vue instead of bundling one) and register it from the bundle's default export:

```js
import Banner from './Banner.vue';

export default function setup(plugin) {
    plugin.registerComponent('Banner', Banner);
}
```

While the plugin is active, Inventoros copies `dist/` to `public/plugin-assets/my-plugin/` and the browser imports the bundle. No npm build of Inventoros is needed, so this works on cPanel and for uploaded plugins.

A plugin can also add a page of its own with `register_page()`, which the bundle supplies with `plugin.registerPage()`, a dashboard card with `register_dashboard_widget()` and a navigation entry with `register_menu_item()`. Plugin pages and routes must be namespaced: route names under `plg.{slug}.*`, page URIs under `/p/{slug}/`, API routes under `/api/v1/plugins/{slug}/` and inbound webhooks under `/webhooks/plugins/{slug}/`. A plugin whose page or route would collide with an application route (or another plugin's) is refused at activation with the reason. Menu items and widget titles can name a translation key under `plugins.{slug}.` (`label_key`, `title_key`), shown in the user's language once the plugin's bundle supplies it. Bundles get the app's layout, Inertia helpers, core UI components (forms, tables, dialogs, the barcode scanner) and translations from `window.Inventoros`, so plugin pages look like the rest of the app and follow the user's language.

Components can be placed on the dashboard, the product, order, purchase order, supplier, customer, category, location, warehouse, stock audit, cycle count, transfer, return and work order pages, and as a card on the settings hub (the full list of pages and slots is in the plugin development guide). Every placement, menu item, widget and page accepts a `permission`. It is checked on the server: users without it never receive the entry, or get a 403 for a page. Data passed as a closure is only computed for users who may see it. A plugin can add permissions of its own with `register_permission('my-plugin.manage', 'Manage My Plugin')`; they appear in the role editor like core permissions and are removed from roles when the plugin is deleted.

## MCP tools and webhook events

`register_mcp_tool('my-plugin', MyTool::class, 'my-plugin.view')` adds a tool to the MCP server. Its name must start with the slug in snake case (`my_plugin_...`), it cannot replace a core tool, and it is listed and callable only for users who hold the permission and whose token allows it.

`register_webhook_event('my-plugin.report_sent', 'When a report is sent')` adds an event to the webhook event picker (the name is the plugin slug, a dot, then the event), and `dispatch_webhook_event('my-plugin.report_sent', $data, $organizationId)` sends it, after the transaction commits, through the same signed and retried delivery as core events.

## Installing and managing plugins

### Who can manage plugins

Plugins are installed for the whole installation and run for every organization. Anyone with the View Plugins permission can browse them. Installing, updating, uploading, activating, deactivating and deleting a plugin needs the Manage Plugins permission in the plugin administrator organization: the organization set by `INVENTOROS_PLUGIN_ADMIN_ORG`, or, when that is not set, the first organization created (the one the installer set up). On a single-organization install this is simply your organization.

Activate or deactivate a plugin over SSH with `php artisan plugin:activate my-plugin` and `php artisan plugin:deactivate my-plugin`.

### Installing from the marketplace

Plugin administrators can install plugins from [inventoros.com/marketplace](https://inventoros.com/marketplace) in one click: open **Plugins > Marketplace** and choose **Install** or **Install and activate**.

Inventoros downloads the plugin on the server and checks its Ed25519 signature against the marketplace public key. The signature covers the plugin's slug, version and sha256 together, and the sha256 is also checked against the catalog. The plugin is then installed with the same safety and version checks as a manual upload. Because marketplace packages are signed, this works even when ZIP uploads are disabled.

The tab shows the installed and latest version of each plugin. **Update** verifies the new version, deactivates the plugin, replaces its files and activates it again, keeping the plugin's data. If the new version fails to activate, the previous one is restored.

Free plugins need no account. For paid plugins you own, create a marketplace connection token on your inventoros.com account page and paste it into the Marketplace tab; it is stored encrypted for your organization.

```bash
INVENTOROS_MARKETPLACE_URL=https://inventoros.com
INVENTOROS_MARKETPLACE_PUBLIC_KEY=base64-ed25519-public-key
```

Inventoros only talks to the configured https origin and never follows redirects. If no marketplace public key is configured, marketplace installs are refused.

### Enabling plugin uploads

Plugin uploads are disabled by default. Enabling them lets any plugin administrator upload a ZIP that is loaded into the application process, which means an admin compromise becomes remote code execution. Review the security notes before turning this on.

```bash
INVENTOROS_ALLOW_PLUGIN_UPLOADS=true
```

The ZIP must contain a single top-level folder named after the plugin slug. Archives are limited to 2,000 entries and 50 MB extracted by default (`INVENTOROS_PLUGIN_MAX_ENTRIES`, `INVENTOROS_PLUGIN_MAX_BYTES`).

### Requiring signed plugins

You can require uploaded plugins to carry a valid detached Ed25519 signature, verified against a public key you control. This is off by default and fails closed when on (an unsigned or mis-signed plugin is rejected).

```bash
INVENTOROS_PLUGIN_SIGNATURE_REQUIRED=true
INVENTOROS_PLUGIN_PUBLIC_KEY=your-base64-public-key
```

Generate a key pair with `php artisan update:signing-keypair`, then sign a plugin with `php artisan update:sign my-plugin.zip` (it reads the secret key from `INVENTOROS_UPDATE_SECRET_KEY`). The command writes the base64 signature to `my-plugin.zip.sig`; paste its contents into the signature field when you upload the ZIP. For production deployments that accept third-party plugins, requiring signatures is strongly recommended.

## Testing a plugin

1. Upload your plugin ZIP through the admin panel (or place the folder in `/plugins`).
2. Activate the plugin and check that its UI appears.
3. Test all functionality thoroughly.
4. Check `storage/logs/laravel.log` and the browser console for errors.
5. Deactivate and verify cleanup, then delete and verify complete removal.

## Paid plugin licences

A paid plugin checks its licence at runtime with `plugin_licence('slug')` (or `plugin_licence('slug', $organizationId)` in jobs and commands). The marketplace signs each organization's entitlements; Inventoros refreshes them daily in the background, verifies them with the marketplace public key and keeps them valid for 14 days past expiry while the marketplace cannot be reached (`INVENTOROS_MARKETPLACE_ENTITLEMENT_GRACE_DAYS`). The status is `valid`, `expired`, `missing` or `unknown`. Only `valid` unlocks paid features: for any other status `allowsWrites()` is false and the plugin should keep working read-only. The check never blocks Inventoros itself.

## Publishing to the marketplace

1. Create an inventoros.com account and apply to become a developer from your account page; an Inventoros admin approves developer accounts.
2. Package the plugin as a ZIP with a single top-level folder named after its slug, containing `plugin.json` (see the full guide for building the UI bundle).
3. Submit it at [inventoros.com/developer](https://inventoros.com/developer). The marketplace validates the ZIP (size, `plugin.json` slug and version, no path traversal or symlinks) and queues it for review.
4. Once approved it is signed with the marketplace key and published. A rejection is emailed to you with the reason.

To release a new version, bump `version` in `plugin.json`, rebuild and re-zip, and submit it as a new version; installs then offer it as an update. Third-party plugins are listed as free for now: paid third-party plugins and developer payouts are not supported yet.
