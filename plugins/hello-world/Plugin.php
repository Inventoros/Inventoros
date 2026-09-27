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

use App\Models\Inventory\Product;
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
        'version' => '1.3.0',
    ],
]);

// A "Hello" tab on the product detail page, next to the core Overview tab.
// The tab bar only appears on pages that have at least one plugin tab.
add_page_component('products.show', 'tabs', [
    'plugin' => 'hello-world',
    'component' => 'HelloWorldTab',
    'label' => 'Hello',
    'permission' => 'view_products',
    'data' => fn ($user) => ['sku' => request()->route('product')?->sku],
]);

// A page of its own at /hello-world. The component comes from the bundle
// (plugin.registerPage('Hello', ...)); props are computed per request.
register_page('hello-world.index', 'Plugin::hello-world/Hello', [
    'uri' => '/hello-world',
    'title' => 'Hello World',
    'props' => fn ($request, $user) => [
        'name' => $user->name,
        'productCount' => $user->hasPermission('view_products')
            ? Product::where('organization_id', $user->organization_id)->count()
            : null,
    ],
]);

// A sidebar link to that page.
register_menu_item([
    'label' => 'Hello World',
    'route' => 'hello-world.index',
    'position' => 999,
]);

// A dashboard widget, shown only to users who can view products. Its data is
// a closure, so the count is never queried for anyone else.
register_dashboard_widget([
    'id' => 'hello-world-products',
    'title' => 'Hello World',
    'plugin' => 'hello-world',
    'component' => 'HelloWorldWidget',
    'width' => 'quarter',
    'permission' => 'view_products',
    'data' => fn ($user) => [
        'productCount' => Product::where('organization_id', $user->organization_id)->count(),
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

