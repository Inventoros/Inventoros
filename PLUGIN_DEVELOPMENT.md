# Plugin Development Guide

## Overview

Inventoros uses a **function-based hook system** inspired by WordPress. All plugin functionality is implemented through hooks and filters using global functions like `add_action()`, `do_action()`, `add_filter()`, and `apply_filters()`.

## Plugin Structure

```
plugins/
  your-plugin/
    ├── plugin.json          (Required: Plugin metadata)
    └── Plugin.php           (Required: Main plugin file)
```

### plugin.json

```json
{
  "name": "Your Plugin Name",
  "description": "What your plugin does",
  "version": "1.0.0",
  "author": "Your Name",
  "author_url": "https://yoursite.com",
  "requires": "1.0.0",
  "main_file": "Plugin.php"
}
```

### Plugin.php

Your main plugin file contains all your hooks and filters:

```php
<?php

// Lifecycle hook: Runs when plugin is activated
add_action('plugin_activated_your-plugin', function () {
    // Create database tables, set up options, etc.
});

// Lifecycle hook: Runs when plugin is deactivated
add_action('plugin_deactivated_your-plugin', function () {
    // Clean up temporary data, clear caches, etc.
});

// Lifecycle hook: Runs when plugin is being deleted
add_action('plugin_uninstalling_your-plugin', function () {
    // Delete database tables, remove all plugin data, etc.
});

// Regular hook: Runs every time plugin loads
add_action('plugin_loaded', function ($slug, $manifest) {
    if ($slug === 'your-plugin') {
        // Your plugin initialization code
    }
});

// Example: Modify product data
add_filter('product_display_name', function ($name, $product) {
    return strtoupper($name);
}, 10);

// Example: React to product creation
add_action('product_created', function ($product) {
    \Log::info('New product created: ' . $product->name);
}, 10);
```

## Available Functions

### Actions (Events)

```php
// Register an action callback
add_action('hook_name', callable $callback, int $priority = 10);

// Trigger an action
do_action('hook_name', ...$args);

// Check if action exists
has_action('hook_name');

// Remove an action
remove_action('hook_name', callable $callback = null);
```

### Filters (Modify Data)

```php
// Register a filter callback
add_filter('filter_name', callable $callback, int $priority = 10);

// Apply filters to a value
$value = apply_filters('filter_name', $value, ...$args);

// Check if filter exists
has_filter('filter_name');

// Remove a filter
remove_filter('filter_name', callable $callback = null);
```

## Lifecycle Hooks

Plugins have access to three lifecycle action hooks:

| Hook | When it runs | Use case |
|------|-------------|----------|
| `plugin_activated_{slug}` | When plugin is activated | Create tables, set defaults |
| `plugin_deactivated_{slug}` | When plugin is deactivated | Clean temp data, disable tasks |
| `plugin_uninstalling_{slug}` | Before plugin deletion | Delete tables, remove all data |

## Common Application Hooks

Here are some built-in hooks you can use:

### Actions
- `plugin_loaded` - Runs when any plugin loads
- `product_created` - After a product is created
- `product_updated` - After a product is updated
- `product_deleted` - After a product is deleted
- `order_created` - After an order is created
- `dashboard_stats` - When dashboard statistics are calculated

### Filters
- `product_display_name` - Modify product name before display
- `dashboard_stats_data` - Modify dashboard statistics
- `product_list_query` - Modify product listing query

## Example: Simple Analytics Plugin

```php
<?php

// Track product views
add_action('product_viewed', function ($product) {
    $views = get_option('product_views_' . $product->id, 0);
    update_option('product_views_' . $product->id, $views + 1);
});

// Add view count to product display
add_filter('product_display_name', function ($name, $product) {
    $views = get_option('product_views_' . $product->id, 0);
    return $name . " ({$views} views)";
}, 10);

// Clean up on uninstall
add_action('plugin_uninstalling_analytics', function () {
    // Delete all view counts
    global $wpdb;
    $wpdb->query("DELETE FROM options WHERE option_name LIKE 'product_views_%'");
});
```

## Installation

1. Create your plugin folder in `plugins/`
2. Create `plugin.json` and `Plugin.php`
3. Zip the folder
4. Upload via admin panel or place directly in `plugins/` directory
5. Activate from the Plugins page

## Extending the UI

Plugins can add custom UI elements to the application:

### Register Menu Items

```php
register_menu_item([
    'label' => 'My Plugin',
    'route' => 'my-plugin.index',
    'icon' => 'M12 6v6m0 0v6m0-6h6m-6 0H6', // SVG path
    'permission' => 'view_my_plugin',
    'position' => 50, // Lower numbers appear first
]);
```

### Register Custom Pages

```php
register_page('my-plugin.index', 'MyPlugin/Index', [
    'permission' => 'view_my_plugin',
    'title' => 'My Plugin Page',
]);
```

### Add Components to Existing Pages

```php
// Add a component to the dashboard header
add_page_component('dashboard', 'header', [
    'component' => 'HelloWorldBanner',  // Component name (without .vue)
    'plugin' => 'hello-world',          // Your plugin slug
    'data' => ['custom' => 'value'],    // Optional data to pass to component
    'position' => 1,                     // Lower numbers appear first
]);

// Available pages and slots:

// Dashboard
// - 'dashboard' => ['header', 'before-stats', 'after-stats', 'before-content', 'after-content', 'widgets', 'footer']

// Products
// - 'products.index' => ['header', 'before-table', 'footer']
// - 'products.show' => ['header', 'sidebar', 'tabs', 'footer']
// - 'products.create' => ['header', 'before-form', 'after-form']
// - 'products.edit' => ['header', 'before-form', 'after-form']

// Orders
// - 'orders.index' => ['header', 'before-table', 'footer']
// - 'orders.show' => ['header', 'sidebar', 'tabs', 'footer']

// Categories & Locations
// - 'categories.index' => ['header', 'footer']
// - 'locations.index' => ['header', 'footer']
```

### Register Dashboard Widgets

```php
register_dashboard_widget([
    'title' => 'My Custom Widget',
    'component' => 'MyPlugin/DashboardWidget',
    'data' => ['stats' => $myStats],
    'position' => 20,
    'width' => 'half', // 'full', 'half', 'third', 'quarter'
]);
```

## Complete Hook Reference

See `app/Services/HookRegistry.php` for a complete list of all available hooks with descriptions and examples. Here are some key ones:

### Product Hooks
- Actions: `product_created`, `product_updated`, `product_deleted`, `product_viewed`
- Filters: `product_display_name`, `product_display_price`, `product_list_query`

### Order Hooks
- Actions: `order_created`, `order_updated`, `order_status_changed`
- Filters: `order_total`, `order_status_options`

### Dashboard Hooks
- Actions: `dashboard_viewed`, `dashboard_stats_calculated`
- Filters: `dashboard_stats_data`, `dashboard_page_data`

### UI Hooks
- Filters: `navigation_menu`, `sidebar_menu`, `user_permissions`

## Best Practices

1. **Use specific hook names** - `plugin_activated_your-plugin` not just generic hooks
2. **Clean up properly** - Always implement uninstall hooks to remove data
3. **Priority matters** - Lower numbers run first (default is 10)
4. **Check for existence** - Use `has_action()` or `has_filter()` before removing
5. **Namespace your data** - Prefix option names with your plugin slug
6. **Handle errors gracefully** - Wrap risky operations in try-catch blocks
7. **Check permissions** - Always verify user permissions before displaying UI elements
8. **Position wisely** - Use position values to control where your UI elements appear

## No File-Based Hooks

Unlike some systems, Inventoros does **not** use file-based hooks (no `hooks/activate.php`, etc.). Everything is handled through function-based hooks in your `Plugin.php` file. This keeps plugins simple and all logic in one place.

## Creating Vue Components for Plugins

Plugins can include Vue.js components that get injected into existing pages. Here's how:

### 1. Plugin Structure with Vue Components

```
plugins/
  your-plugin/
    ├── plugin.json
    ├── Plugin.php
    └── resources/
        └── js/
            ├── app.js              # Entry point (required)
            └── Components/
                └── YourComponent.vue
```

### 2. Create Your Component

Create your Vue component in `plugins/your-plugin/resources/js/Components/YourComponent.vue`:

```vue
<script setup>
import { ref } from 'vue';

// Accept props passed from the plugin registration
const props = defineProps({
    message: String,
    data: Object,
});
</script>

<template>
    <div class="mb-6 bg-white dark:bg-dark-card border rounded-lg p-6">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white">
            {{ message }}
        </h3>
        <!-- Your component content -->
    </div>
</template>
```

### 3. Create Plugin Entry Point

Create `plugins/your-plugin/resources/js/app.js`:

```javascript
// Export your components
import YourComponent from './Components/YourComponent.vue';

export {
    YourComponent,
};
```

### 4. Register Component in Plugin.php

```php
add_action('plugin_loaded', function ($slug, $manifest) {
    if ($slug === 'your-plugin') {
        add_page_component('dashboard', 'header', [
            'component' => 'YourComponent',    // Name matches export
            'plugin' => 'your-plugin',          // Your plugin slug
            'data' => [                         // Optional props
                'message' => 'Hello from plugin!',
                'data' => ['key' => 'value'],
            ],
            'position' => 10,
        ]);
    }
});
```

### Important Notes

- Vue components must be in `plugins/{plugin}/resources/js/Components/`
- Components are loaded dynamically at runtime
- All components must be exported from `resources/js/app.js`
- Use Tailwind CSS classes for styling (both light and dark mode)
- Components receive props via the `data` parameter in registration

## Getting Help

- Check the example plugin at `plugins/hello-world/`
- Review the Hook facade at `app/Facades/Hook.php`
- Review HookRegistry at `app/Services/HookRegistry.php` for all available hooks
- Look at helper functions in `app/Support/helpers.php`
- See `resources/js/Components/PluginSlot.vue` for slot rendering logic
