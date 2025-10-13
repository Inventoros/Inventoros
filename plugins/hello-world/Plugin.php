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
 * - How to log plugin activity
 * - How plugins integrate with InventorOS
 *
 * Delete me whenever you want! I'm just here for moral support.
 */

// Say hello when we load!
add_action('plugin_loaded', function ($slug, $manifest) {
    if ($slug === 'hello-world') {
        \Illuminate\Support\Facades\Log::info('👋 Hello World! The plugin is loaded and doing... well, nothing really.', [
            'version' => $manifest['version'] ?? 'unknown',
            'message' => 'But hey, at least I exist!',
        ]);
    }
});

// Uncomment these examples to see them in action (but they'll actually do things then!)

// Example: Log when products are created
// add_action('product_created', function ($product) {
//     \Illuminate\Support\Facades\Log::info('Hello World says: A product was born! 🎉', [
//         'product_name' => $product->name,
//     ]);
// }, 10);

// Example: Add a silly prefix to product names
// add_filter('product_display_name', function ($name, $product) {
//     return '✨ ' . $name . ' ✨';
// }, 10);

// Example: Track dashboard views
// add_action('dashboard_stats', function () {
//     \Illuminate\Support\Facades\Log::info('Hello World: Someone is looking at the dashboard! 👀');
// });

/**
 * That's it! This plugin literally does nothing except say hello in the logs.
 * Want to make it do something? Uncomment the examples above!
 * Want to delete it? Go right ahead - we won't tell anyone. 🤐
 */
