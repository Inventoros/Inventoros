# InventorOS Plugin Development Guide

Welcome to the InventorOS Plugin Development Guide. This document will help you create powerful extensions for InventorOS using our WordPress-style hook and filter system.

## Table of Contents

1. [Introduction](#introduction)
2. [Getting Started](#getting-started)
3. [Plugin Structure](#plugin-structure)
4. [Plugin Manifest](#plugin-manifest)
5. [Hooks and Filters](#hooks-and-filters)
6. [Lifecycle Hooks](#lifecycle-hooks)
7. [Available Hooks](#available-hooks)
8. [Available Filters](#available-filters)
9. [Best Practices](#best-practices)
10. [Examples](#examples)

## Introduction

InventorOS features a powerful plugin system that allows developers to extend and customize the application without modifying core files. The system is inspired by WordPress and uses actions (hooks) and filters to let plugins interact with the application.

### Key Concepts

- **Plugins** are stored in the `/plugins` directory at the project root
- **Actions (Hooks)** let you execute code at specific points in the application
- **Filters** let you modify data before it's used or displayed
- **Lifecycle Hooks** run during plugin activation, deactivation, and uninstall

## Getting Started

### Prerequisites

- Basic PHP knowledge
- Understanding of Laravel framework
- Familiarity with InventorOS structure

### Creating Your First Plugin

1. Create a new directory in `/plugins` with your plugin slug (e.g., `/plugins/my-plugin`)
2. Create a `plugin.json` manifest file
3. Create a main plugin file (e.g., `Plugin.php`)
4. Add your hooks and filters
5. Upload via the admin panel or place manually in the plugins directory

## Plugin Structure

A basic plugin structure looks like this:

```
my-plugin/
├── plugin.json          # Required: Plugin metadata
├── Plugin.php           # Required: Main plugin file
├── hooks/               # Optional: Lifecycle hooks
│   ├── activate.php     # Runs on activation
│   ├── deactivate.php   # Runs on deactivation
│   └── uninstall.php    # Runs on deletion
├── src/                 # Optional: Additional PHP classes
│   └── MyClass.php
├── assets/              # Optional: CSS, JS, images
│   ├── css/
│   ├── js/
│   └── images/
└── README.md            # Optional: Documentation
```

## Plugin Manifest

Every plugin requires a `plugin.json` file with the following structure:

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

### Manifest Fields

| Field | Required | Description |
|-------|----------|-------------|
| `name` | Yes | Display name of your plugin |
| `description` | Yes | Brief description (max 255 chars recommended) |
| `version` | Yes | Plugin version (semantic versioning) |
| `author` | Yes | Author name |
| `author_url` | No | Author website URL |
| `requires` | Yes | Minimum InventorOS version required |
| `main_file` | Yes | Entry point PHP file (default: `Plugin.php`) |

## Hooks and Filters

InventorOS provides eight global functions for working with hooks and filters:

### Action Functions

```php
// Add an action hook
add_action('hook_name', callable $callback, int $priority = 10);

// Execute an action
do_action('hook_name', ...$args);

// Check if action exists
has_action('hook_name');

// Remove an action
remove_action('hook_name', ?callable $callback = null);
```

### Filter Functions

```php
// Add a filter hook
add_filter('filter_name', callable $callback, int $priority = 10);

// Apply filters to a value
$value = apply_filters('filter_name', $value, ...$args);

// Check if filter exists
has_filter('filter_name');

// Remove a filter
remove_filter('filter_name', ?callable $callback = null);
```

### Priority

Priority determines the order hooks/filters execute. Lower numbers run first (default: 10).

```php
add_action('product_created', 'send_notification', 5);  // Runs first
add_action('product_created', 'update_analytics', 10); // Runs second
add_action('product_created', 'log_event', 20);        // Runs last
```

## Lifecycle Hooks

Lifecycle hooks run at specific points in your plugin's lifecycle.

### Activation Hook (`hooks/activate.php`)

Runs when the plugin is activated. Use for:
- Creating database tables
- Setting default options
- Scheduling tasks
- Initial setup

```php
<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (!Schema::hasTable('my_plugin_data')) {
    Schema::create('my_plugin_data', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained();
        $table->string('key');
        $table->text('value');
        $table->timestamps();
    });
}
```

### Deactivation Hook (`hooks/deactivate.php`)

Runs when the plugin is deactivated. Use for:
- Clearing caches
- Unscheduling tasks
- Cleaning temporary data

```php
<?php
use Illuminate\Support\Facades\Cache;

Cache::forget('my_plugin_cache');
```

### Uninstall Hook (`hooks/uninstall.php`)

Runs when the plugin is deleted. Use for:
- Dropping database tables
- Removing all plugin data
- Complete cleanup

```php
<?php
use Illuminate\Support\Facades\Schema;

Schema::dropIfExists('my_plugin_data');
```

## Available Hooks

### Core Application Hooks

#### `plugin_loaded`
Fires when a plugin is loaded.

```php
add_action('plugin_loaded', function ($slug, $manifest) {
    // Plugin initialization code
}, 10);
```

**Parameters:**
- `$slug` (string): Plugin slug
- `$manifest` (array): Plugin manifest data

### Product Hooks

#### `product_created`
Fires after a product is created.

```php
add_action('product_created', function ($product) {
    // Send notification
    // Update external system
    // Log event
}, 10);
```

**Parameters:**
- `$product` (Product): The created product model

#### `product_updated`
Fires after a product is updated.

```php
add_action('product_updated', function ($product, $oldData) {
    // Track changes
    // Sync with external systems
}, 10);
```

**Parameters:**
- `$product` (Product): The updated product model
- `$oldData` (array): Original product data

#### `product_deleted`
Fires before a product is deleted.

```php
add_action('product_deleted', function ($product) {
    // Clean up related data
    // Notify users
}, 10);
```

**Parameters:**
- `$product` (Product): The product being deleted

### Order Hooks

#### `order_created`
Fires after an order is created.

```php
add_action('order_created', function ($order) {
    // Send confirmation email
    // Update inventory
    // Notify warehouse
}, 10);
```

**Parameters:**
- `$order` (Order): The created order model

#### `order_status_changed`
Fires when an order status changes.

```php
add_action('order_status_changed', function ($order, $oldStatus, $newStatus) {
    // Send status update email
    // Trigger workflows
}, 10);
```

**Parameters:**
- `$order` (Order): The order model
- `$oldStatus` (string): Previous status
- `$newStatus` (string): New status

### Dashboard Hooks

#### `dashboard_stats`
Fires when dashboard statistics are rendered.

```php
add_action('dashboard_stats', function () {
    // Add custom stats
    // Log analytics
}, 10);
```

## Available Filters

Filters allow you to modify data before it's used or displayed.

### Product Filters

#### `product_display_name`
Modify product name before display.

```php
add_filter('product_display_name', function ($name, $product) {
    // Add prefix/suffix
    // Translate
    // Format
    return $name;
}, 10);
```

**Parameters:**
- `$name` (string): Product name
- `$product` (Product): Product model

**Returns:** Modified name (string)

#### `product_price_display`
Modify product price before display.

```php
add_filter('product_price_display', function ($price, $product) {
    // Apply discounts
    // Add markup
    // Currency conversion
    return $price;
}, 10);
```

**Parameters:**
- `$price` (float): Product price
- `$product` (Product): Product model

**Returns:** Modified price (float)

#### `product_search_query`
Modify the product search query.

```php
add_filter('product_search_query', function ($query, $searchTerm) {
    // Add custom search logic
    // Search additional fields
    return $query;
}, 10);
```

**Parameters:**
- `$query` (Builder): Laravel query builder instance
- `$searchTerm` (string): Search term

**Returns:** Modified query (Builder)

### Order Filters

#### `order_total_calculation`
Modify order total calculation.

```php
add_filter('order_total_calculation', function ($total, $order) {
    // Add fees
    // Apply discounts
    // Custom tax calculation
    return $total;
}, 10);
```

**Parameters:**
- `$total` (float): Calculated total
- `$order` (Order): Order model

**Returns:** Modified total (float)

## Best Practices

### 1. Use Descriptive Hook Names
```php
// Good
add_action('my_plugin_send_notification', $callback);

// Bad
add_action('send', $callback);
```

### 2. Always Return Values in Filters
```php
// Good
add_filter('product_price', function ($price, $product) {
    return $price * 1.1;
}, 10);

// Bad - doesn't return
add_filter('product_price', function ($price, $product) {
    $price * 1.1;
}, 10);
```

### 3. Check for Existing Data
```php
add_action('activate', function () {
    if (!Schema::hasTable('my_table')) {
        Schema::create('my_table', function ($table) {
            // ...
        });
    }
});
```

### 4. Namespace Your Plugin
```php
namespace MyPlugin;

class MyClass {
    // Your code
}
```

### 5. Handle Errors Gracefully
```php
add_action('product_created', function ($product) {
    try {
        // Your code
    } catch (\Exception $e) {
        \Log::error('My Plugin Error: ' . $e->getMessage());
    }
});
```

### 6. Respect Organization Scoping
Always filter data by organization_id when querying the database:

```php
$products = Product::where('organization_id', $organizationId)->get();
```

### 7. Clean Up After Yourself
Always implement the uninstall hook to remove your plugin's data:

```php
// hooks/uninstall.php
Schema::dropIfExists('my_plugin_table');
```

## Examples

### Example 1: Email Notification Plugin

```php
<?php
// plugins/email-notifications/Plugin.php

use App\Models\Inventory\Product;
use Illuminate\Support\Facades\Mail;

// Send email when product is low on stock
add_action('product_updated', function ($product) {
    if ($product->isLowStock()) {
        Mail::to('admin@example.com')->send(
            new \App\Mail\LowStockAlert($product)
        );
    }
}, 10);

// Add custom product field
add_filter('product_display_name', function ($name, $product) {
    if ($product->isLowStock()) {
        return '⚠️ ' . $name;
    }
    return $name;
}, 10);
```

### Example 2: Custom Pricing Plugin

```php
<?php
// plugins/dynamic-pricing/Plugin.php

use App\Models\Inventory\Product;

// Apply volume discounts
add_filter('product_price_display', function ($price, $product) {
    $discount = 0;

    if ($product->stock > 100) {
        $discount = 0.10; // 10% discount
    } elseif ($product->stock > 50) {
        $discount = 0.05; // 5% discount
    }

    return $price * (1 - $discount);
}, 10);

// Log price changes
add_action('product_updated', function ($product, $oldData) {
    if ($oldData['price'] !== $product->price) {
        \Log::info('Price changed', [
            'product' => $product->name,
            'old_price' => $oldData['price'],
            'new_price' => $product->price,
        ]);
    }
}, 10);
```

### Example 3: Analytics Plugin

```php
<?php
// plugins/analytics/Plugin.php

use App\Models\Inventory\Product;
use App\Models\Order\Order;
use Illuminate\Support\Facades\DB;

// Track product views
add_action('product_viewed', function ($product) {
    DB::table('plugin_analytics_views')->insert([
        'product_id' => $product->id,
        'organization_id' => $product->organization_id,
        'viewed_at' => now(),
    ]);
}, 10);

// Track conversions
add_action('order_created', function ($order) {
    foreach ($order->items as $item) {
        DB::table('plugin_analytics_conversions')->insert([
            'product_id' => $item->product_id,
            'order_id' => $order->id,
            'organization_id' => $order->organization_id,
            'quantity' => $item->quantity,
            'revenue' => $item->total,
            'converted_at' => now(),
        ]);
    }
}, 10);
```

## Testing Your Plugin

1. **Upload** your plugin ZIP through the admin panel
2. **Activate** the plugin
3. **Test** all functionality thoroughly
4. **Check logs** for any errors: `storage/logs/laravel.log`
5. **Deactivate** and verify cleanup
6. **Delete** and verify complete removal

## Debugging

Enable debug mode in `.env`:

```env
APP_DEBUG=true
LOG_LEVEL=debug
```

Add logging to your plugin:

```php
\Log::debug('My Plugin: Something happened', ['data' => $data]);
```

## Need Help?

- Check the example plugin in `/plugins/example-plugin`
- Review the core hook implementations
- Open an issue on GitHub
- Join our community forum

## Publishing Your Plugin

When ready to share your plugin:

1. Create comprehensive README.md
2. Add version number to plugin.json
3. Test thoroughly across different organizations
4. Create a ZIP file of your plugin directory
5. Share on the InventorOS plugin marketplace

---

Happy plugin development! 🚀
