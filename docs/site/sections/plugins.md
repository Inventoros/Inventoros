Inventoros has a WordPress-style plugin system for extending the application without modifying core files. Plugins use actions to run code at specific points and filters to change data before it is used or displayed, and can add their own UI to existing pages.

This is a condensed overview. The full guide, with every available action and filter, lives in the repository: https://github.com/Inventoros/Inventoros/blob/main/docs/PLUGIN_DEVELOPMENT.md

Each release also attaches `hello-world-plugin.zip`, a small working example you can upload and read. It is also listed on the [marketplace](https://inventoros.com/marketplace), so you can install it from **Plugins > Marketplace**.

### Key concepts

- Plugins live in the `/plugins` directory at the project root, one folder per plugin. The folder name is the plugin's slug.
- Actions let you execute code at specific points in the application.
- Filters let you modify data before it is used or displayed.
- Lifecycle files run once on activation, deactivation and deletion.
- A plugin's UI ships as a pre-built bundle, so it works on installs that never run npm.
- All plugin data must respect multi-tenancy. Always scope queries by `organization_id`.

### Plugin structure

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

`requires` is the minimum Inventoros version and `requires_php` the minimum PHP version. Inventoros refuses to upload or activate a plugin whose requirements are not met, and says why. `ui` points at the built bundle inside `dist/`.

### Hooks and filters

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
});
```

### Lifecycle files

`hooks/activate.php` runs after the plugin loads on activation; create tables and defaults there. If it throws, the plugin stays inactive and the Plugins page shows the error. `hooks/deactivate.php` runs on deactivation and `hooks/uninstall.php` when the plugin is deleted. If either throws, the plugin is still deactivated or removed and the page shows a warning.

### Adding UI

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

A plugin can also add a page of its own with `register_page()`, which the bundle supplies with `plugin.registerPage()`, and a dashboard card with `register_dashboard_widget()`. Bundles get the app's layout, Inertia helpers, core UI components (forms, tables, dialogs, the barcode scanner) and translations from `window.Inventoros`, so plugin pages look like the rest of the app and follow the user's language.

Every placement, menu item, widget and page accepts a `permission`. It is checked on the server: users without it never receive the entry, or get a 403 for a page. Data passed as a closure is only computed for users who may see it. A plugin can add permissions of its own with `register_permission('my-plugin.manage', 'Manage My Plugin')`; they appear in the role editor like core permissions and are removed from roles when the plugin is deleted.

### MCP tools and webhook events

`register_mcp_tool('my-plugin', MyTool::class, 'my-plugin.view')` adds a tool to the MCP server. Its name must start with the slug in snake case (`my_plugin_...`), and it is listed and callable only for users who hold the permission and whose token allows it.

`register_webhook_event('my-plugin.report_sent', 'When a report is sent')` adds an event to the webhook event picker, and `dispatch_webhook_event('my-plugin.report_sent', $data, $organizationId)` sends it, after the transaction commits, through the same signed and retried delivery as core events.

Activate or deactivate a plugin over SSH with `php artisan plugin:activate my-plugin` and `php artisan plugin:deactivate my-plugin`.

### Installing from the marketplace

Admins with the Manage Plugins permission can install plugins from [inventoros.com/marketplace](https://inventoros.com/marketplace) in one click: open **Plugins > Marketplace** and choose **Install** or **Install and activate**.

Inventoros downloads the plugin on the server, checks its Ed25519 signature against the marketplace public key and its sha256 against the catalog, then installs it with the same safety and version checks as a manual upload. Because marketplace packages are signed, this works even when ZIP uploads are disabled.

The tab shows the installed and latest version of each plugin. **Update** verifies the new version, deactivates the plugin, replaces its files and activates it again, keeping the plugin's data. If the new version fails to activate, the previous one is restored.

Free plugins need no account. For paid plugins you own, create a marketplace connection token on your inventoros.com account page and paste it into the Marketplace tab; it is stored encrypted for your organization.

```bash
INVENTOROS_MARKETPLACE_URL=https://inventoros.com
INVENTOROS_MARKETPLACE_PUBLIC_KEY=base64-ed25519-public-key
```

Inventoros only talks to the configured https origin and never follows redirects. If no marketplace public key is configured, marketplace installs are refused.

### Enabling plugin uploads

Plugin uploads are disabled by default. Enabling them lets any admin user upload a ZIP that is loaded into the application process, which means an admin compromise becomes remote code execution. Review the security notes before turning this on.

```bash
INVENTOROS_ALLOW_PLUGIN_UPLOADS=true
```

The ZIP must contain a single top-level folder named after the plugin slug.

### Requiring signed plugins

You can require uploaded plugins to carry a valid detached Ed25519 signature, verified against a public key you control. This is off by default and fails closed when on (an unsigned or mis-signed plugin is rejected).

```bash
INVENTOROS_PLUGIN_SIGNATURE_REQUIRED=true
INVENTOROS_PLUGIN_PUBLIC_KEY=your-base64-public-key
```

Sign a plugin with `php artisan update:sign my-plugin.zip`. For production deployments that accept third-party plugins, requiring signatures is strongly recommended.

### Testing a plugin

1. Upload your plugin ZIP through the admin panel (or place the folder in `/plugins`).
2. Activate the plugin and check that its UI appears.
3. Test all functionality thoroughly.
4. Check `storage/logs/laravel.log` and the browser console for errors.
5. Deactivate and verify cleanup, then delete and verify complete removal.

### Paid plugin licences

A paid plugin checks its licence at runtime with `plugin_licence('slug')` (or `plugin_licence('slug', $organizationId)` in jobs and commands). The marketplace signs each organization's entitlements; Inventoros refreshes them daily in the background, verifies them with the marketplace public key and keeps them valid for 14 days past expiry while the marketplace cannot be reached (`INVENTOROS_MARKETPLACE_ENTITLEMENT_GRACE_DAYS`). The status is `valid`, `expired`, `missing` or `unknown`; anything but `valid` makes the plugin read-only. The check never blocks Inventoros itself.

### Publishing to the marketplace

1. Create an inventoros.com account and apply to become a developer from your account page; an Inventoros admin approves developer accounts.
2. Package the plugin as a ZIP with a single top-level folder named after its slug, containing `plugin.json` (see the full guide for building the UI bundle).
3. Submit it at [inventoros.com/developer](https://inventoros.com/developer). The marketplace validates the ZIP (size, `plugin.json` slug and version, no path traversal or symlinks) and queues it for review.
4. Once approved it is signed with the marketplace key and published. A rejection is emailed to you with the reason.

To release a new version, bump `version` in `plugin.json`, rebuild and re-zip, and submit it as a new version; installs then offer it as an update. Third-party plugins are listed as free for now: paid third-party plugins and developer payouts are not supported yet.
