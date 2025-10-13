# InventorOS Plugins

This directory contains plugins for InventorOS. Plugins extend the functionality of the system using a WordPress-style hooks and filters architecture.

## Plugin System Overview

InventorOS uses a powerful plugin system that allows developers to extend and modify functionality without touching core code. The system provides:

- **Actions**: Execute custom code at specific points in the application lifecycle
- **Filters**: Modify data before it's used or displayed
- **Priority System**: Control the order in which hooks are executed
- **Easy Integration**: Simple API for adding and removing hooks

## Creating a Plugin

### Basic Structure

Every plugin should be placed in its own directory within `plugins/` with the following structure:

```
your-plugin/
├── plugin.json           # Plugin metadata (required)
├── Plugin.php            # Main plugin class (required)
├── hooks/                # Lifecycle hooks (optional)
│   ├── activate.php      # Runs when plugin is activated
│   ├── deactivate.php    # Runs when plugin is deactivated
│   └── uninstall.php     # Runs when plugin is deleted
└── README.md             # Documentation (recommended)
```

### plugin.json

Every plugin must have a `plugin.json` file with metadata:

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

The main plugin file should contain a class that extends the base plugin functionality:

```php
<?php

namespace Plugins\YourPlugin;

class Plugin
{
    public function __construct()
    {
        // Register your hooks here
        add_action('app.boot', [$this, 'onAppBoot']);
        add_filter('product.price', [$this, 'modifyPrice'], 10, 2);
    }

    public function onAppBoot()
    {
        // Your code here
    }

    public function modifyPrice($price, $product)
    {
        // Modify and return the price
        return $price;
    }
}
```

## Available Hooks

### Actions

Actions allow you to execute code at specific points:

```php
// Add an action
add_action('hook_name', function($arg1, $arg2) {
    // Your code here
}, $priority = 10);

// Execute an action
do_action('hook_name', $arg1, $arg2);

// Check if action exists
if (has_action('hook_name')) {
    // Action has callbacks
}

// Remove an action
remove_action('hook_name', $callback);
```

### Filters

Filters allow you to modify data:

```php
// Add a filter
add_filter('filter_name', function($value, $arg1) {
    // Modify and return the value
    return $value;
}, $priority = 10);

// Apply filters
$modified_value = apply_filters('filter_name', $original_value, $arg1);

// Check if filter exists
if (has_filter('filter_name')) {
    // Filter has callbacks
}

// Remove a filter
remove_filter('filter_name', $callback);
```

## Priority System

Hooks support a priority system (default: 10). Lower numbers run first:

```php
// This runs first (priority 5)
add_action('init', 'early_function', 5);

// This runs second (priority 10, default)
add_action('init', 'normal_function');

// This runs last (priority 20)
add_action('init', 'late_function', 20);
```

## Lifecycle Hooks

### hooks/activate.php

Runs when the plugin is activated. Use for setup tasks:

```php
<?php
// Create database tables, set default options, etc.
\Log::info('Plugin activated');
```

### hooks/deactivate.php

Runs when the plugin is deactivated:

```php
<?php
// Clean up temporary data, disable features, etc.
\Log::info('Plugin deactivated');
```

### hooks/uninstall.php

Runs when the plugin is deleted. Use for complete cleanup:

```php
<?php
// Remove database tables, delete files, etc.
\Log::info('Plugin uninstalled');
```

## Helper Functions

The following global helper functions are available:

- `add_action($tag, $callback, $priority = 10)` - Register an action hook
- `do_action($tag, ...$args)` - Execute all callbacks for an action
- `has_action($tag)` - Check if an action has callbacks
- `remove_action($tag, $callback = null)` - Remove an action hook
- `add_filter($tag, $callback, $priority = 10)` - Register a filter hook
- `apply_filters($tag, $value, ...$args)` - Apply all filters to a value
- `has_filter($tag)` - Check if a filter has callbacks
- `remove_filter($tag, $callback = null)` - Remove a filter hook

## Example Plugin

Check out the `hello-world` plugin in this directory for a complete working example with comments and demonstrations of the hook system.

## Best Practices

1. **Use Unique Hook Names**: Prefix your custom hooks with your plugin name to avoid conflicts
2. **Document Your Hooks**: If your plugin provides hooks for other plugins, document them clearly
3. **Clean Up**: Always implement proper cleanup in uninstall.php
4. **Version Control**: Use semantic versioning for your plugin
5. **Error Handling**: Always handle errors gracefully
6. **Testing**: Test activation, deactivation, and uninstallation thoroughly

## Security Notes

- Never execute arbitrary code from user input
- Sanitize all data before using it in hooks
- Validate permissions before performing sensitive operations
- Be careful with file operations in lifecycle hooks

## Getting Help

- Check the `hello-world` plugin for examples
- Review the `app/Support/HookManager.php` for implementation details
- Consult the InventorOS documentation

## License

Plugins can use their own licenses. Core plugin system is part of InventorOS.
