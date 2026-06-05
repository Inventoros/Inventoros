Inventoros has a WordPress-style plugin system that lets you extend and customize the application without modifying core files. Plugins use actions (hooks) to run code at specific points and filters to modify data before it is used or displayed.

This is a condensed overview. The full guide, including every available hook and filter, lives in the repository: https://github.com/Inventoros/Inventoros/blob/main/docs/PLUGIN_DEVELOPMENT.md

### Key concepts

- Plugins live in the `/plugins` directory at the project root.
- Actions (hooks) let you execute code at specific points in the application.
- Filters let you modify data before it is used or displayed.
- Lifecycle hooks run during plugin activation, deactivation, and uninstall.
- All plugin data must respect multi-tenancy. Always scope queries by `organization_id`.

### Plugin structure

A basic plugin looks like this:

```text
my-plugin/
  plugin.json          # Required: plugin metadata
  Plugin.php           # Required: main plugin file
  hooks/               # Optional: lifecycle hooks
    activate.php       # Runs on activation
    deactivate.php     # Runs on deactivation
    uninstall.php      # Runs on deletion
  src/                 # Optional: additional PHP classes
  assets/              # Optional: CSS, JS, images
  README.md            # Optional: documentation
```

### The plugin.json manifest

Every plugin requires a `plugin.json` manifest:

```json
{
    "name": "My Awesome Plugin",
    "description": "A brief description of what your plugin does",
    "version": "1.0.0",
    "author": "Your Name",
    "author_url": "https://yourwebsite.com",
    "requires": "1.0.0",
    "main_file": "Plugin.php"
}
```

Required fields: `name`, `description`, `version`, `author`, `requires` (minimum Inventoros version), and `main_file` (entry point, default `Plugin.php`). `author_url` is optional.

### Hooks and filters

Inventoros exposes WordPress-style global functions. Actions run code:

```php
add_action('product_created', function ($product) {
    // Send a notification, sync to an external system, log an event.
}, 10);
```

Filters modify and return a value:

```php
add_filter('product_price_display', function ($price, $product) {
    return $price * 0.9; // Apply a 10% discount.
}, 10);
```

Priority determines order; lower numbers run first (default 10). Commonly used hooks include `product_created`, `product_updated`, `product_deleted`, `order_created`, and `order_status_changed`. Common filters include `product_display_name`, `product_price_display`, `product_search_query`, and `order_total_calculation`.

Lifecycle hooks live in the `hooks/` directory: `activate.php` (create tables, set defaults), `deactivate.php` (clear caches, unschedule tasks), and `uninstall.php` (drop tables, remove all plugin data).

### Enabling plugin uploads

Plugin uploads are disabled by default. Enabling them lets any admin user upload a ZIP that is loaded into the application process, which means an admin compromise becomes remote code execution. Review the security notes before turning this on.

Enable uploads in `.env`:

```bash
INVENTOROS_ALLOW_PLUGIN_UPLOADS=true
```

### Requiring signed plugins

You can optionally require uploaded plugins to carry a valid detached Ed25519 signature, verified against a public key you control, before they are installed. This is off by default and fails closed when on (an unsigned or mis-signed plugin is rejected).

```bash
INVENTOROS_PLUGIN_SIGNATURE_REQUIRED=true
INVENTOROS_PLUGIN_PUBLIC_KEY=your-base64-public-key
```

Sign a plugin with `php artisan update:sign`. For production deployments that accept third-party plugins, requiring signatures is strongly recommended.

### Testing a plugin

1. Upload your plugin ZIP through the admin panel (or place it manually in `/plugins`).
2. Activate the plugin.
3. Test all functionality thoroughly.
4. Check `storage/logs/laravel.log` for errors.
5. Deactivate and verify cleanup, then delete and verify complete removal.
