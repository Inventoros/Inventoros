<?php

/**
 * Hello World Plugin for InventorOS
 *
 * This plugin does absolutely nothing useful and that's perfectly okay!
 * It's here to show you how plugins work and give you something to delete
 * when you're feeling productive. Go ahead, we won't judge. 😊
 *
 * Seriously though, this demonstrates:
 * - How to use hooks and filters
 * - How to handle lifecycle events (activate, deactivate, uninstall)
 * - How to log plugin activity
 * - How plugins integrate with InventorOS
 *
 * Delete me whenever you want! I'm just here for moral support.
 */

// ========================================
// LIFECYCLE HOOKS (completely optional!)
// ========================================

// Runs when the plugin is activated
add_action('plugin_activated_hello-world', function () {
    \Illuminate\Support\Facades\Log::info('🎉 Hello World plugin was activated! Time to do... nothing!');

    // This is where you'd typically:
    // - Create database tables
    // - Set up default options
    // - Initialize plugin data
});

// Runs when the plugin is deactivated
add_action('plugin_deactivated_hello-world', function () {
    \Illuminate\Support\Facades\Log::info('😢 Hello World plugin was deactivated. We had a good run!');

    // This is where you'd typically:
    // - Clean up temporary data
    // - Clear caches
    // - Disable scheduled tasks
});

// Runs when the plugin is being deleted
add_action('plugin_uninstalling_hello-world', function () {
    \Illuminate\Support\Facades\Log::info('👋 Hello World plugin is being deleted. Goodbye cruel world!');

    // This is where you'd typically:
    // - Delete database tables
    // - Remove all plugin data
    // - Clean up any files created by the plugin
});

// ========================================
// REGULAR PLUGIN CODE
// ========================================

// Say hello when we load!
add_action('plugin_loaded', function ($slug, $manifest) {
    if ($slug === 'hello-world') {
        \Illuminate\Support\Facades\Log::info('👋 Hello World! The plugin is loaded and doing... well, nothing really.', [
            'version' => $manifest['version'] ?? 'unknown',
            'message' => 'But hey, at least I exist!',
        ]);

        // Register the Hello World banner to appear on the dashboard
        add_page_component('dashboard', 'header', [
            'component' => 'HelloWorldBanner',
            'plugin' => 'hello-world',
            'position' => 1,
        ]);
    }
});

// ========================================
// EXAMPLES (all commented out - uncomment to try!)
// ========================================

// Example 1: Log when products are created
// add_action('product_created', function ($product) {
//     \Illuminate\Support\Facades\Log::info('Hello World says: A product was born! 🎉', [
//         'product_name' => $product->name,
//     ]);
// }, 10);

// Example 2: Add a silly prefix to product names
// add_filter('product_display_name', function ($name, $product) {
//     return '✨ ' . $name . ' ✨';
// }, 10);

// Example 3: Track dashboard views
// add_action('dashboard_viewed', function ($user) {
//     \Illuminate\Support\Facades\Log::info('Hello World: Someone is looking at the dashboard! 👀', [
//         'user' => $user->name,
//     ]);
// });

// Example 4: Add a custom menu item
// register_menu_item([
//     'label' => 'Hello World',
//     'route' => 'dashboard', // Just link to dashboard for now
//     'icon' => 'M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
//     'position' => 999, // Show at the end
// ]);

// Example 5: Add custom data to dashboard stats
// add_filter('dashboard_stats_data', function ($stats, $user) {
//     $stats['hello_world_views'] = rand(1, 100); // Random number for fun
//     return $stats;
// }, 10);

// Example 6: Add a widget to product show page
// add_page_component('product.show', 'sidebar', [
//     'component' => 'HelloWorldWidget', // Would need to create this Vue component
//     'data' => ['message' => 'Hello from plugin!'],
//     'position' => 999,
// ]);

// Example 7: Modify the product list query (show only active products)
// add_filter('product_list_query', function ($query, $request) {
//     return $query->where('is_active', true);
// }, 10);

/**
 * That's it! This plugin literally does nothing except say hello in the logs.
 * Want to make it do something? Uncomment the examples above!
 * Want to delete it? Go right ahead - we won't tell anyone. 🤐
 *
 * For more examples and documentation, see:
 * - PLUGIN_DEVELOPMENT.md
 * - app/Services/HookRegistry.php
 */
