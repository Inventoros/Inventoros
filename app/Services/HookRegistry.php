<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Central registry of all available hooks and filters in the application.
 *
 * This class serves as documentation - plugins can reference this to know
 * what actions and filters are available for hooking into application events.
 */
final class HookRegistry
{
    /**
     * Get all available action hooks.
     *
     * @return array<string, array{description: string, parameters: array<int, string>, example: string}> Action hooks keyed by hook name
     */
    public static function getActions(): array
    {
        return [
            // ========================================
            // PLUGIN LIFECYCLE
            // ========================================
            'plugin_loaded' => [
                'description' => 'Fired when a plugin is loaded',
                'parameters' => ['$slug', '$manifest'],
                'example' => "add_action('plugin_loaded', function(\$slug, \$manifest) { /* ... */ });",
            ],
            'plugin_activated' => [
                'description' => 'Fired when any plugin is activated',
                'parameters' => ['$slug'],
                'example' => "add_action('plugin_activated', function(\$slug) { /* ... */ });",
            ],
            'plugin_activated_{slug}' => [
                'description' => 'Fired when a specific plugin is activated (replace {slug} with your plugin name)',
                'parameters' => [],
                'example' => "add_action('plugin_activated_my-plugin', function() { /* ... */ });",
            ],
            'plugin_deactivated' => [
                'description' => 'Fired when any plugin is deactivated',
                'parameters' => ['$slug'],
                'example' => "add_action('plugin_deactivated', function(\$slug) { /* ... */ });",
            ],
            'plugin_deactivated_{slug}' => [
                'description' => 'Fired when a specific plugin is deactivated',
                'parameters' => [],
                'example' => "add_action('plugin_deactivated_my-plugin', function() { /* ... */ });",
            ],
            'plugin_uninstalling' => [
                'description' => 'Fired before any plugin is deleted',
                'parameters' => ['$slug'],
                'example' => "add_action('plugin_uninstalling', function(\$slug) { /* ... */ });",
            ],
            'plugin_uninstalling_{slug}' => [
                'description' => 'Fired before a specific plugin is deleted',
                'parameters' => [],
                'example' => "add_action('plugin_uninstalling_my-plugin', function() { /* ... */ });",
            ],

            // ========================================
            // PRODUCT HOOKS
            // ========================================
            'product_before_create' => [
                'description' => 'Fired before a product is created',
                'parameters' => ['$validated_data', '$request'],
                'example' => "add_action('product_before_create', function(\$data, \$request) { /* ... */ });",
            ],
            'product_created' => [
                'description' => 'Fired after a product is created',
                'parameters' => ['$product', '$user'],
                'example' => "add_action('product_created', function(\$product, \$user) { /* ... */ });",
            ],
            'product_after_create' => [
                'description' => 'Fired after a product is created (alternative hook)',
                'parameters' => ['$product', '$request'],
                'example' => "add_action('product_after_create', function(\$product, \$request) { /* ... */ });",
            ],
            'product_before_update' => [
                'description' => 'Fired before a product is updated',
                'parameters' => ['$product', '$validated_data', '$request'],
                'example' => "add_action('product_before_update', function(\$product, \$data, \$request) { /* ... */ });",
            ],
            'product_updated' => [
                'description' => 'Fired after a product is updated',
                'parameters' => ['$product', '$user'],
                'example' => "add_action('product_updated', function(\$product, \$user) { /* ... */ });",
            ],
            'product_after_update' => [
                'description' => 'Fired after a product is updated (alternative hook)',
                'parameters' => ['$product', '$request'],
                'example' => "add_action('product_after_update', function(\$product, \$request) { /* ... */ });",
            ],
            'product_before_delete' => [
                'description' => 'Fired before a product is deleted',
                'parameters' => ['$product', '$request'],
                'example' => "add_action('product_before_delete', function(\$product, \$request) { /* ... */ });",
            ],
            'product_deleted' => [
                'description' => 'Fired after a product is deleted',
                'parameters' => ['$product', '$user'],
                'example' => "add_action('product_deleted', function(\$product, \$user) { /* ... */ });",
            ],
            'product_after_delete' => [
                'description' => 'Fired after a product is deleted (alternative hook)',
                'parameters' => ['$product', '$request'],
                'example' => "add_action('product_after_delete', function(\$product, \$request) { /* ... */ });",
            ],
            'product_viewed' => [
                'description' => 'Fired when a product detail page is viewed',
                'parameters' => ['$product', '$user'],
                'example' => "add_action('product_viewed', function(\$product, \$user) { /* ... */ });",
            ],
            'product_list_viewed' => [
                'description' => 'Fired when the product list page is viewed',
                'parameters' => ['$products', '$user'],
                'example' => "add_action('product_list_viewed', function(\$products, \$user) { /* ... */ });",
            ],

            // ========================================
            // ORDER HOOKS
            // ========================================
            'order_before_create' => [
                'description' => 'Fired before an order is created',
                'parameters' => ['$validated_data', '$request'],
                'example' => "add_action('order_before_create', function(\$data, \$request) { /* ... */ });",
            ],
            'order_created' => [
                'description' => 'Fired after an order is created',
                'parameters' => ['$order', '$user'],
                'example' => "add_action('order_created', function(\$order, \$user) { /* ... */ });",
            ],
            'order_updated' => [
                'description' => 'Fired after an order is updated',
                'parameters' => ['$order', '$user'],
                'example' => "add_action('order_updated', function(\$order, \$user) { /* ... */ });",
            ],
            'order_deleted' => [
                'description' => 'Fired after an order is deleted',
                'parameters' => ['$order', '$user'],
                'example' => "add_action('order_deleted', function(\$order, \$user) { /* ... */ });",
            ],
            'order_status_changed' => [
                'description' => 'Fired when an order status changes',
                'parameters' => ['$order', '$old_status', '$new_status', '$user'],
                'example' => "add_action('order_status_changed', function(\$order, \$old, \$new, \$user) { /* ... */ });",
            ],

            // ========================================
            // CATEGORY HOOKS
            // ========================================
            'category_created' => [
                'description' => 'Fired after a category is created',
                'parameters' => ['$category', '$user'],
                'example' => "add_action('category_created', function(\$category, \$user) { /* ... */ });",
            ],
            'category_updated' => [
                'description' => 'Fired after a category is updated',
                'parameters' => ['$category', '$user'],
                'example' => "add_action('category_updated', function(\$category, \$user) { /* ... */ });",
            ],
            'category_deleted' => [
                'description' => 'Fired after a category is deleted',
                'parameters' => ['$category', '$user'],
                'example' => "add_action('category_deleted', function(\$category, \$user) { /* ... */ });",
            ],

            // ========================================
            // LOCATION HOOKS
            // ========================================
            'location_created' => [
                'description' => 'Fired after a location is created',
                'parameters' => ['$location', '$user'],
                'example' => "add_action('location_created', function(\$location, \$user) { /* ... */ });",
            ],
            'location_updated' => [
                'description' => 'Fired after a location is updated',
                'parameters' => ['$location', '$user'],
                'example' => "add_action('location_updated', function(\$location, \$user) { /* ... */ });",
            ],
            'location_deleted' => [
                'description' => 'Fired after a location is deleted',
                'parameters' => ['$location', '$user'],
                'example' => "add_action('location_deleted', function(\$location, \$user) { /* ... */ });",
            ],

            // ========================================
            // USER HOOKS
            // ========================================
            'user_created' => [
                'description' => 'Fired after a user is created',
                'parameters' => ['$user', '$creator'],
                'example' => "add_action('user_created', function(\$user, \$creator) { /* ... */ });",
            ],
            'user_updated' => [
                'description' => 'Fired after a user is updated',
                'parameters' => ['$user', '$updater'],
                'example' => "add_action('user_updated', function(\$user, \$updater) { /* ... */ });",
            ],
            'user_deleted' => [
                'description' => 'Fired after a user is deleted',
                'parameters' => ['$user', '$deleter'],
                'example' => "add_action('user_deleted', function(\$user, \$deleter) { /* ... */ });",
            ],
            'user_logged_in' => [
                'description' => 'Fired when a user logs in',
                'parameters' => ['$user'],
                'example' => "add_action('user_logged_in', function(\$user) { /* ... */ });",
            ],
            'user_logged_out' => [
                'description' => 'Fired when a user logs out',
                'parameters' => ['$user'],
                'example' => "add_action('user_logged_out', function(\$user) { /* ... */ });",
            ],

            // ========================================
            // DASHBOARD HOOKS
            // ========================================
            'dashboard_stats_calculated' => [
                'description' => 'Fired when dashboard statistics are calculated',
                'parameters' => ['$stats', '$user'],
                'example' => "add_action('dashboard_stats_calculated', function(\$stats, \$user) { /* ... */ });",
            ],
            'dashboard_viewed' => [
                'description' => 'Fired when the dashboard is viewed',
                'parameters' => ['$user'],
                'example' => "add_action('dashboard_viewed', function(\$user) { /* ... */ });",
            ],

            // ========================================
            // STOCK HOOKS
            // ========================================
            'stock_adjusted' => [
                'description' => 'Fired when product stock is adjusted',
                'parameters' => ['$stock_adjustment', '$product'],
                'example' => "add_action('stock_adjusted', function(\$adjustment, \$product) { /* ... */ });",
            ],
            'low_stock_alert' => [
                'description' => 'Fired when product stock falls below minimum',
                'parameters' => ['$product'],
                'example' => "add_action('low_stock_alert', function(\$product) { /* ... */ });",
            ],
            'out_of_stock_alert' => [
                'description' => 'Fired when product stock reaches zero',
                'parameters' => ['$product'],
                'example' => "add_action('out_of_stock_alert', function(\$product) { /* ... */ });",
            ],
        ];
    }

    /**
     * Get all available filter hooks.
     *
     * @return array<string, array{description: string, parameters: array<int, string>, example: string}> Filter hooks keyed by hook name
     */
    public static function getFilters(): array
    {
        return [
            // ========================================
            // PRODUCT FILTERS
            // ========================================
            'product_display_name' => [
                'description' => 'Modify product name before display',
                'parameters' => ['$name', '$product'],
                'example' => "add_filter('product_display_name', function(\$name, \$product) { return strtoupper(\$name); });",
            ],
            'product_display_price' => [
                'description' => 'Modify product price before display',
                'parameters' => ['$price', '$product'],
                'example' => "add_filter('product_display_price', function(\$price, \$product) { return \$price * 1.1; });",
            ],
            'product_list_query' => [
                'description' => 'Modify the product listing database query',
                'parameters' => ['$query', '$request'],
                'example' => "add_filter('product_list_query', function(\$query, \$request) { return \$query->where('featured', true); });",
            ],
            'product_list_data' => [
                'description' => 'Modify the product collection before rendering list',
                'parameters' => ['$products', '$request'],
                'example' => "add_filter('product_list_data', function(\$products, \$request) { /* modify */ return \$products; });",
            ],
            'product_list_page_data' => [
                'description' => 'Modify all data passed to product list page',
                'parameters' => ['$data', '$request'],
                'example' => "add_filter('product_list_page_data', function(\$data, \$request) { \$data['custom'] = 'value'; return \$data; });",
            ],
            'product_show_data' => [
                'description' => 'Modify product before displaying detail page',
                'parameters' => ['$product', '$user'],
                'example' => "add_filter('product_show_data', function(\$product, \$user) { /* modify */ return \$product; });",
            ],
            'product_show_page_data' => [
                'description' => 'Modify all data passed to product show page',
                'parameters' => ['$data', '$product'],
                'example' => "add_filter('product_show_page_data', function(\$data, \$product) { \$data['custom'] = 'value'; return \$data; });",
            ],
            'product_store_validation_rules' => [
                'description' => 'Modify validation rules for creating products',
                'parameters' => ['$rules', '$request'],
                'example' => "add_filter('product_store_validation_rules', function(\$rules, \$request) { \$rules['custom_field'] = 'required'; return \$rules; });",
            ],
            'product_store_data' => [
                'description' => 'Modify validated data before creating product',
                'parameters' => ['$validated_data', '$request'],
                'example' => "add_filter('product_store_data', function(\$data, \$request) { \$data['custom'] = 'value'; return \$data; });",
            ],
            'product_store_response' => [
                'description' => 'Modify the response after creating a product',
                'parameters' => ['$response', '$product', '$request'],
                'example' => "add_filter('product_store_response', function(\$response, \$product, \$request) { return redirect()->route('custom.route'); });",
            ],
            'product_update_validation_rules' => [
                'description' => 'Modify validation rules for updating products',
                'parameters' => ['$rules', '$product', '$request'],
                'example' => "add_filter('product_update_validation_rules', function(\$rules, \$product, \$request) { return \$rules; });",
            ],
            'product_update_data' => [
                'description' => 'Modify validated data before updating product',
                'parameters' => ['$validated_data', '$product', '$request'],
                'example' => "add_filter('product_update_data', function(\$data, \$product, \$request) { return \$data; });",
            ],

            // ========================================
            // DASHBOARD FILTERS
            // ========================================
            'dashboard_stats_data' => [
                'description' => 'Modify dashboard statistics data',
                'parameters' => ['$stats', '$user'],
                'example' => "add_filter('dashboard_stats_data', function(\$stats, \$user) { \$stats['custom_metric'] = 100; return \$stats; });",
            ],
            'dashboard_page_data' => [
                'description' => 'Modify all data passed to dashboard page',
                'parameters' => ['$data', '$user'],
                'example' => "add_filter('dashboard_page_data', function(\$data, \$user) { return \$data; });",
            ],

            // ========================================
            // ORDER FILTERS
            // ========================================
            'order_list_query' => [
                'description' => 'Modify the order listing database query',
                'parameters' => ['$query', '$request'],
                'example' => "add_filter('order_list_query', function(\$query, \$request) { return \$query; });",
            ],
            'order_total' => [
                'description' => 'Modify order total before calculation',
                'parameters' => ['$total', '$order'],
                'example' => "add_filter('order_total', function(\$total, \$order) { return \$total * 1.1; });",
            ],
            'order_status_options' => [
                'description' => 'Modify available order status options',
                'parameters' => ['$statuses'],
                'example' => "add_filter('order_status_options', function(\$statuses) { \$statuses[] = 'custom_status'; return \$statuses; });",
            ],

            // ========================================
            // UI FILTERS
            // ========================================
            'navigation_menu' => [
                'description' => 'Add or modify navigation menu items',
                'parameters' => ['$menu_items', '$user'],
                'example' => "add_filter('navigation_menu', function(\$items, \$user) { \$items[] = ['name' => 'Custom', 'route' => 'custom.route']; return \$items; });",
            ],
            'sidebar_menu' => [
                'description' => 'Add or modify sidebar menu items',
                'parameters' => ['$menu_items', '$user'],
                'example' => "add_filter('sidebar_menu', function(\$items, \$user) { return \$items; });",
            ],
            'user_permissions' => [
                'description' => 'Modify user permissions',
                'parameters' => ['$permissions', '$user'],
                'example' => "add_filter('user_permissions', function(\$permissions, \$user) { \$permissions[] = 'custom_permission'; return \$permissions; });",
            ],

            // ========================================
            // EXPORT/IMPORT FILTERS
            // ========================================
            'export_products_query' => [
                'description' => 'Modify query for product export',
                'parameters' => ['$query', '$filters'],
                'example' => "add_filter('export_products_query', function(\$query, \$filters) { return \$query; });",
            ],
            'export_products_data' => [
                'description' => 'Modify product data before export',
                'parameters' => ['$data', '$product'],
                'example' => "add_filter('export_products_data', function(\$data, \$product) { \$data['custom'] = 'value'; return \$data; });",
            ],
            'import_products_data' => [
                'description' => 'Modify product data during import',
                'parameters' => ['$data', '$row'],
                'example' => "add_filter('import_products_data', function(\$data, \$row) { return \$data; });",
            ],
        ];
    }

    /**
     * Get all hooks (both actions and filters).
     *
     * @return array{actions: array, filters: array} All hooks grouped by type
     */
    public static function getAllHooks(): array
    {
        return [
            'actions' => self::getActions(),
            'filters' => self::getFilters(),
        ];
    }
}
