<?php

/**
 * Hello World plugin for Inventoros.
 *
 * It does nothing useful, and that is fine: it exists to show how a plugin is
 * put together. Read it alongside docs/PLUGIN_DEVELOPMENT.md.
 *
 *  - Plugin.php (this file) is loaded on every request while the plugin is
 *    active. Register actions, filters and UI placements here.
 *  - hooks/activate.php, hooks/deactivate.php and hooks/uninstall.php run once
 *    at those points in the plugin's lifecycle.
 *  - ui/ holds the source of the dashboard banner; dist/ holds its pre-built
 *    bundle, which Inventoros publishes and loads in the browser at runtime.
 */

use Illuminate\Support\Facades\Log;

// ========================================
// UI PLACEMENT
// ========================================

// Put the banner at the top of the dashboard. The component itself comes from
// the runtime bundle (ui/src/main.js registers "HelloWorldBanner").
add_page_component('dashboard', 'header', [
    'plugin' => 'hello-world',
    'component' => 'HelloWorldBanner',
    'position' => 1,
    'data' => [
        'version' => '1.1.0',
    ],
]);

// ========================================
// ACTIONS
// ========================================

add_action('plugin_loaded', function ($slug, $manifest) {
    if ($slug === 'hello-world') {
        Log::debug('Hello World plugin loaded', ['version' => $manifest['version'] ?? 'unknown']);
    }
});

// ========================================
// EXAMPLES (uncomment to try)
// ========================================

// Log every new product, whichever surface created it.
// add_action('product_created', function ($product, $user) {
//     Log::info('Hello World: a product was created', ['sku' => $product->sku]);
// });

// Decorate product names on the list and detail pages, the REST API and search.
// add_filter('product_display_name', function ($name, $product) {
//     return $product->isLowStock() ? $name.' (low stock)' : $name;
// });

// Show a 10% discount on displayed prices (the stored price is unchanged).
// add_filter('product_price_display', function ($price, $product) {
//     return $price === null ? null : round($price * 0.9, 2);
// });

// Let product search also match the notes field.
// add_filter('product_search_query', function ($query, $term) {
//     return $query->orWhere('notes', 'like', '%'.$term.'%');
// });

// Add a number to the dashboard statistics.
// add_filter('dashboard_stats_data', function ($stats, $user) {
//     $stats['hello_world_greetings'] = 1;
//     return $stats;
// });

// Add a sidebar menu item.
// register_menu_item([
//     'label' => 'Hello World',
//     'route' => 'dashboard',
//     'position' => 999,
// ]);
