# Inventoros plugins

Each folder here is one plugin; the folder name is its slug. Plugins are managed from **Admin > Plugins**.

- `hello-world/` is a working example: actions, filters, lifecycle files (`hooks/`) and a UI bundle built from `ui/` into `dist/`. Start by copying it.
- The full guide, with every action and filter the application fires, is [docs/PLUGIN_DEVELOPMENT.md](../docs/PLUGIN_DEVELOPMENT.md).

Minimal plugin:

```text
my-plugin/
  plugin.json
  Plugin.php
```

```json
{
    "name": "My Plugin",
    "description": "What it does",
    "version": "1.0.0",
    "author": "You",
    "requires": "1.0.8",
    "main_file": "Plugin.php"
}
```

```php
<?php
// Plugin.php: loaded on every request while the plugin is active.

add_action('product_created', function ($product, $user) {
    \Illuminate\Support\Facades\Log::info('Product created', ['sku' => $product->sku]);
});

add_filter('product_display_name', function ($name, $product) {
    return $product->isLowStock() ? $name.' (low stock)' : $name;
});
```
