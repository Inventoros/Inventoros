Inventoros has a WordPress-style plugin system for extending the application without modifying core files. Plugins use actions to run code at specific points and filters to change data before it is used or displayed, and can add their own UI to existing pages.

This is a condensed overview. The full guide, with every available action and filter, lives in the repository: https://github.com/Inventoros/Inventoros/blob/main/docs/PLUGIN_DEVELOPMENT.md

Each release also attaches `hello-world-plugin.zip`, a small working example you can upload and read.

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

### The plugin.json manifest

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

While the plugin is active, Inventoros copies `dist/` to `public/plugins/my-plugin/` and the browser imports the bundle. No npm build of Inventoros is needed, so this works on cPanel and for uploaded plugins.

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
