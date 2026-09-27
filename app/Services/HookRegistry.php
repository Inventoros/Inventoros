<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Central registry of every action and filter the application fires.
 *
 * This is the source of truth plugin authors (and docs/PLUGIN_DEVELOPMENT.md)
 * rely on. Tests\Feature\PluginHookDocumentationTest keeps it honest: every
 * hook listed here must be fired somewhere under app/, every hook fired under
 * app/ must be listed here, and every hook the plugin guides mention must be
 * listed here.
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
                'description' => 'Fired after a plugin\'s main file is loaded (at boot for every active plugin, and on activation)',
                'parameters' => ['$slug', '$manifest'],
                'example' => "add_action('plugin_loaded', function (\$slug, \$manifest) { /* ... */ });",
            ],
            'plugin_activated' => [
                'description' => 'Fired when any plugin is activated, after its hooks/activate.php ran',
                'parameters' => ['$slug'],
                'example' => "add_action('plugin_activated', function (\$slug) { /* ... */ });",
            ],
            'plugin_activated_{slug}' => [
                'description' => 'Fired when a specific plugin is activated (replace {slug} with the plugin folder name)',
                'parameters' => [],
                'example' => "add_action('plugin_activated_my-plugin', function () { /* ... */ });",
            ],
            'plugin_deactivated' => [
                'description' => 'Fired when any plugin is deactivated, before its hooks/deactivate.php runs',
                'parameters' => ['$slug'],
                'example' => "add_action('plugin_deactivated', function (\$slug) { /* ... */ });",
            ],
            'plugin_deactivated_{slug}' => [
                'description' => 'Fired when a specific plugin is deactivated',
                'parameters' => [],
                'example' => "add_action('plugin_deactivated_my-plugin', function () { /* ... */ });",
            ],
            'plugin_uninstalling' => [
                'description' => 'Fired before any plugin is deleted, before its hooks/uninstall.php runs',
                'parameters' => ['$slug'],
                'example' => "add_action('plugin_uninstalling', function (\$slug) { /* ... */ });",
            ],
            'plugin_uninstalling_{slug}' => [
                'description' => 'Fired before a specific plugin is deleted',
                'parameters' => [],
                'example' => "add_action('plugin_uninstalling_my-plugin', function () { /* ... */ });",
            ],

            // ========================================
            // PRODUCT HOOKS
            // ========================================
            'product_created' => [
                'description' => 'Fired after a product is created on any surface (web, REST, GraphQL, MCP, import)',
                'parameters' => ['$product', '$user'],
                'example' => "add_action('product_created', function (\$product, \$user) { /* ... */ });",
            ],
            'product_before_create' => [
                'description' => 'Fired before a product is created from the web form',
                'parameters' => ['$validated_data', '$request'],
                'example' => "add_action('product_before_create', function (\$validated_data, \$request) { /* ... */ });",
            ],
            'product_after_create' => [
                'description' => 'Fired after a product is created from the web form',
                'parameters' => ['$product', '$request'],
                'example' => "add_action('product_after_create', function (\$product, \$request) { /* ... */ });",
            ],
            'product_before_update' => [
                'description' => 'Fired before a product is updated from the web form; the product still holds its old values',
                'parameters' => ['$product', '$validated_data', '$request'],
                'example' => "add_action('product_before_update', function (\$product, \$validated_data, \$request) { /* ... */ });",
            ],
            'product_updated' => [
                'description' => 'Fired after a product is updated from the web form',
                'parameters' => ['$product', '$user'],
                'example' => "add_action('product_updated', function (\$product, \$user) { /* ... */ });",
            ],
            'product_after_update' => [
                'description' => 'Fired after a product is updated from the web form',
                'parameters' => ['$product', '$request'],
                'example' => "add_action('product_after_update', function (\$product, \$request) { /* ... */ });",
            ],
            'product_before_delete' => [
                'description' => 'Fired before a product is deleted from the web UI',
                'parameters' => ['$product', '$request'],
                'example' => "add_action('product_before_delete', function (\$product, \$request) { /* ... */ });",
            ],
            'product_deleted' => [
                'description' => 'Fired after a product is deleted from the web UI',
                'parameters' => ['$product', '$user'],
                'example' => "add_action('product_deleted', function (\$product, \$user) { /* ... */ });",
            ],
            'product_after_delete' => [
                'description' => 'Fired after a product is deleted from the web UI',
                'parameters' => ['$product', '$request'],
                'example' => "add_action('product_after_delete', function (\$product, \$request) { /* ... */ });",
            ],
            'product_viewed' => [
                'description' => 'Fired when a product detail page is viewed',
                'parameters' => ['$product', '$user'],
                'example' => "add_action('product_viewed', function (\$product, \$user) { /* ... */ });",
            ],
            'product_list_viewed' => [
                'description' => 'Fired when the product list page is viewed',
                'parameters' => ['$products', '$user'],
                'example' => "add_action('product_list_viewed', function (\$products, \$user) { /* ... */ });",
            ],

            // ========================================
            // STOCK HOOKS
            // ========================================
            'stock_adjusted' => [
                'description' => 'Fired after a stock adjustment is committed ($product is null for a variant adjustment)',
                'parameters' => ['$stock_adjustment', '$product'],
                'example' => "add_action('stock_adjusted', function (\$stock_adjustment, \$product) { /* ... */ });",
            ],
            'low_stock_alert' => [
                'description' => 'Fired when product stock drops to or below its minimum',
                'parameters' => ['$product'],
                'example' => "add_action('low_stock_alert', function (\$product) { /* ... */ });",
            ],
            'out_of_stock_alert' => [
                'description' => 'Fired when product stock reaches zero',
                'parameters' => ['$product'],
                'example' => "add_action('out_of_stock_alert', function (\$product) { /* ... */ });",
            ],
            'warehouse_low_stock_alert' => [
                'description' => 'Fired when a product\'s on-hand quantity in one warehouse drops to or below its minimum stock level for that warehouse',
                'parameters' => ['$product', '$warehouse', '$on_hand'],
                'example' => "add_action('warehouse_low_stock_alert', function (\$product, \$warehouse, \$on_hand) { /* ... */ });",
            ],

            // ========================================
            // ORDER HOOKS
            // ========================================
            'order_created' => [
                'description' => 'Fired after an order and all its items are created',
                'parameters' => ['$order', '$user'],
                'example' => "add_action('order_created', function (\$order, \$user) { /* ... */ });",
            ],
            'order_updated' => [
                'description' => 'Fired after an order is saved',
                'parameters' => ['$order', '$user'],
                'example' => "add_action('order_updated', function (\$order, \$user) { /* ... */ });",
            ],
            'order_status_changed' => [
                'description' => 'Fired when an order status changes',
                'parameters' => ['$order', '$old_status', '$new_status', '$user'],
                'example' => "add_action('order_status_changed', function (\$order, \$old_status, \$new_status, \$user) { /* ... */ });",
            ],
            'order_approved' => [
                'description' => 'Fired when an order is approved',
                'parameters' => ['$order', '$user'],
                'example' => "add_action('order_approved', function (\$order, \$user) { /* ... */ });",
            ],
            'order_rejected' => [
                'description' => 'Fired when an order is rejected',
                'parameters' => ['$order', '$user'],
                'example' => "add_action('order_rejected', function (\$order, \$user) { /* ... */ });",
            ],

            // ========================================
            // PURCHASE ORDER HOOKS
            // ========================================
            'purchase_order_created' => [
                'description' => 'Fired after a purchase order is created',
                'parameters' => ['$purchase_order', '$user'],
                'example' => "add_action('purchase_order_created', function (\$purchase_order, \$user) { /* ... */ });",
            ],
            'purchase_order_received' => [
                'description' => 'Fired when a purchase order becomes fully received',
                'parameters' => ['$purchase_order', '$user'],
                'example' => "add_action('purchase_order_received', function (\$purchase_order, \$user) { /* ... */ });",
            ],
            'purchase_order_cancelled' => [
                'description' => 'Fired when a purchase order is cancelled',
                'parameters' => ['$purchase_order', '$user'],
                'example' => "add_action('purchase_order_cancelled', function (\$purchase_order, \$user) { /* ... */ });",
            ],

            // ========================================
            // CUSTOMER HOOKS
            // ========================================
            'customer_created' => [
                'description' => 'Fired after a customer is created on any surface',
                'parameters' => ['$customer', '$user'],
                'example' => "add_action('customer_created', function (\$customer, \$user) { /* ... */ });",
            ],
            'customer_updated' => [
                'description' => 'Fired after a customer is updated on any surface',
                'parameters' => ['$customer', '$user'],
                'example' => "add_action('customer_updated', function (\$customer, \$user) { /* ... */ });",
            ],
            'customer_deleted' => [
                'description' => 'Fired after a customer is deleted on any surface',
                'parameters' => ['$customer', '$user'],
                'example' => "add_action('customer_deleted', function (\$customer, \$user) { /* ... */ });",
            ],

            // ========================================
            // RETURN HOOKS
            // ========================================
            'return_created' => [
                'description' => 'Fired after a return is created',
                'parameters' => ['$return_order', '$user'],
                'example' => "add_action('return_created', function (\$return_order, \$user) { /* ... */ });",
            ],
            'return_received' => [
                'description' => 'Fired when a return is marked received',
                'parameters' => ['$return_order', '$user'],
                'example' => "add_action('return_received', function (\$return_order, \$user) { /* ... */ });",
            ],

            // ========================================
            // TRANSFER, AUDIT AND WORK ORDER HOOKS
            // ========================================
            'transfer_created' => [
                'description' => 'Fired after a stock transfer is created',
                'parameters' => ['$transfer', '$user'],
                'example' => "add_action('transfer_created', function (\$transfer, \$user) { /* ... */ });",
            ],
            'transfer_completed' => [
                'description' => 'Fired when a stock transfer is completed',
                'parameters' => ['$transfer', '$user'],
                'example' => "add_action('transfer_completed', function (\$transfer, \$user) { /* ... */ });",
            ],
            'stock_audit_completed' => [
                'description' => 'Fired when a stock audit is completed',
                'parameters' => ['$stock_audit', '$user'],
                'example' => "add_action('stock_audit_completed', function (\$stock_audit, \$user) { /* ... */ });",
            ],
            'work_order_completed' => [
                'description' => 'Fired when a work order is completed',
                'parameters' => ['$work_order', '$user'],
                'example' => "add_action('work_order_completed', function (\$work_order, \$user) { /* ... */ });",
            ],

            // ========================================
            // SUPPLIER HOOKS
            // ========================================
            'supplier_created' => [
                'description' => 'Fired after a supplier is created from the web UI',
                'parameters' => ['$supplier', '$user'],
                'example' => "add_action('supplier_created', function (\$supplier, \$user) { /* ... */ });",
            ],
            'supplier_updated' => [
                'description' => 'Fired after a supplier is updated from the web UI',
                'parameters' => ['$supplier', '$user'],
                'example' => "add_action('supplier_updated', function (\$supplier, \$user) { /* ... */ });",
            ],
            'supplier_before_delete' => [
                'description' => 'Fired before a supplier is deleted from the web UI',
                'parameters' => ['$supplier', '$user'],
                'example' => "add_action('supplier_before_delete', function (\$supplier, \$user) { /* ... */ });",
            ],
            'supplier_deleted' => [
                'description' => 'Fired after a supplier is deleted from the web UI',
                'parameters' => ['$supplier', '$user'],
                'example' => "add_action('supplier_deleted', function (\$supplier, \$user) { /* ... */ });",
            ],
            'supplier_viewed' => [
                'description' => 'Fired when a supplier detail page is viewed',
                'parameters' => ['$supplier', '$user'],
                'example' => "add_action('supplier_viewed', function (\$supplier, \$user) { /* ... */ });",
            ],
            'supplier_list_viewed' => [
                'description' => 'Fired when the supplier list page is viewed',
                'parameters' => ['$suppliers', '$user'],
                'example' => "add_action('supplier_list_viewed', function (\$suppliers, \$user) { /* ... */ });",
            ],

            // ========================================
            // DASHBOARD HOOKS
            // ========================================
            'dashboard_stats_calculated' => [
                'description' => 'Fired after the dashboard statistics are calculated (and filtered)',
                'parameters' => ['$stats', '$user'],
                'example' => "add_action('dashboard_stats_calculated', function (\$stats, \$user) { /* ... */ });",
            ],
            'dashboard_stats' => [
                'description' => 'Alias of dashboard_stats_calculated, fired right after it with the same arguments',
                'parameters' => ['$stats', '$user'],
                'example' => "add_action('dashboard_stats', function (\$stats, \$user) { /* ... */ });",
            ],
            'dashboard_viewed' => [
                'description' => 'Fired when the dashboard is viewed',
                'parameters' => ['$user'],
                'example' => "add_action('dashboard_viewed', function (\$user) { /* ... */ });",
            ],

            // ========================================
            // EMAIL HOOKS
            // ========================================
            'email_notification_sent' => [
                'description' => 'Fired after a notification email is queued',
                'parameters' => ['$type', '$user', '$data'],
                'example' => "add_action('email_notification_sent', function (\$type, \$user, \$data) { /* ... */ });",
            ],
            'email_notification_failed' => [
                'description' => 'Fired when queueing a notification email fails',
                'parameters' => ['$type', '$user', '$data', '$exception'],
                'example' => "add_action('email_notification_failed', function (\$type, \$user, \$data, \$exception) { /* ... */ });",
            ],
        ];
    }

    /**
     * Get all available filter hooks.
     *
     * A `user_permissions` filter is deliberately NOT offered: letting a
     * plugin rewrite the authenticated user's effective permissions is a
     * privilege-escalation vector, so permission resolution
     * (User::getAllPermissions) does not pass through any filter.
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
                'description' => 'Modify the product name shown on the product list and detail pages, the REST product resource (display_name) and global search. The stored name is unchanged',
                'parameters' => ['$name', '$product'],
                'example' => "add_filter('product_display_name', function (\$name, \$product) { return \$name.' (sale)'; });",
            ],
            'product_price_display' => [
                'description' => 'Modify the product price shown on the product list and detail pages and the REST product resource (display_price). The stored price is unchanged',
                'parameters' => ['$price', '$product'],
                'example' => "add_filter('product_price_display', function (\$price, \$product) { return round(\$price * 0.9, 2); });",
            ],
            'product_search_query' => [
                'description' => 'Extend a product search (product list, global search, REST product list). Receives the nested OR group holding the search conditions; add alternatives with orWhere()',
                'parameters' => ['$query', '$search_term'],
                'example' => "add_filter('product_search_query', function (\$query, \$search_term) { return \$query->orWhere('notes', 'like', '%'.\$search_term.'%'); });",
            ],
            'product_list_query' => [
                'description' => 'Modify the product list query (tenant scope is re-applied afterwards)',
                'parameters' => ['$query', '$request'],
                'example' => "add_filter('product_list_query', function (\$query, \$request) { return \$query->where('is_active', true); });",
            ],
            'product_list_data' => [
                'description' => 'Modify the paginated products before the list page renders',
                'parameters' => ['$products', '$request'],
                'example' => "add_filter('product_list_data', function (\$products, \$request) { return \$products; });",
            ],
            'product_list_page_data' => [
                'description' => 'Modify all props passed to the product list page',
                'parameters' => ['$data', '$request'],
                'example' => "add_filter('product_list_page_data', function (\$data, \$request) { \$data['custom'] = 'value'; return \$data; });",
            ],
            'product_show_data' => [
                'description' => 'Modify the product before the detail page renders',
                'parameters' => ['$product', '$user'],
                'example' => "add_filter('product_show_data', function (\$product, \$user) { return \$product; });",
            ],
            'product_show_page_data' => [
                'description' => 'Modify all props passed to the product detail page',
                'parameters' => ['$data', '$product'],
                'example' => "add_filter('product_show_page_data', function (\$data, \$product) { \$data['custom'] = 'value'; return \$data; });",
            ],
            'product_store_validation_rules' => [
                'description' => 'Modify validation rules for creating a product from the web form',
                'parameters' => ['$rules', '$request'],
                'example' => "add_filter('product_store_validation_rules', function (\$rules, \$request) { \$rules['custom_field'] = 'nullable|string'; return \$rules; });",
            ],
            'product_store_data' => [
                'description' => 'Modify validated data before a product is created from the web form',
                'parameters' => ['$validated_data', '$request'],
                'example' => "add_filter('product_store_data', function (\$validated_data, \$request) { return \$validated_data; });",
            ],
            'product_store_response' => [
                'description' => 'Replace the response after a product is created from the web form',
                'parameters' => ['$response', '$product', '$request'],
                'example' => "add_filter('product_store_response', function (\$response, \$product, \$request) { return \$response; });",
            ],
            'product_update_validation_rules' => [
                'description' => 'Modify validation rules for updating a product from the web form',
                'parameters' => ['$rules', '$product', '$request'],
                'example' => "add_filter('product_update_validation_rules', function (\$rules, \$product, \$request) { return \$rules; });",
            ],
            'product_update_data' => [
                'description' => 'Modify validated data before a product is updated from the web form',
                'parameters' => ['$validated_data', '$product', '$request'],
                'example' => "add_filter('product_update_data', function (\$validated_data, \$product, \$request) { return \$validated_data; });",
            ],

            // ========================================
            // ORDER FILTERS
            // ========================================
            'order_total_calculation' => [
                'description' => 'Modify the computed order total when an order is created or edited',
                'parameters' => ['$total', '$order'],
                'example' => "add_filter('order_total_calculation', function (\$total, \$order) { return \$total; });",
            ],

            // ========================================
            // SUPPLIER FILTERS
            // ========================================
            'supplier_list_query' => [
                'description' => 'Modify the supplier list query (tenant scope is re-applied afterwards)',
                'parameters' => ['$query', '$request'],
                'example' => "add_filter('supplier_list_query', function (\$query, \$request) { return \$query; });",
            ],
            'supplier_list_data' => [
                'description' => 'Modify the paginated suppliers before the list page renders',
                'parameters' => ['$suppliers', '$request'],
                'example' => "add_filter('supplier_list_data', function (\$suppliers, \$request) { return \$suppliers; });",
            ],
            'supplier_list_page_data' => [
                'description' => 'Modify all props passed to the supplier list page',
                'parameters' => ['$data', '$request'],
                'example' => "add_filter('supplier_list_page_data', function (\$data, \$request) { return \$data; });",
            ],
            'supplier_before_create' => [
                'description' => 'Modify validated data before a supplier is created',
                'parameters' => ['$validated_data', '$request'],
                'example' => "add_filter('supplier_before_create', function (\$validated_data, \$request) { return \$validated_data; });",
            ],
            'supplier_before_show' => [
                'description' => 'Modify the supplier before its detail page renders',
                'parameters' => ['$supplier', '$request'],
                'example' => "add_filter('supplier_before_show', function (\$supplier, \$request) { return \$supplier; });",
            ],
            'supplier_before_update' => [
                'description' => 'Modify validated data before a supplier is updated',
                'parameters' => ['$validated_data', '$supplier', '$request'],
                'example' => "add_filter('supplier_before_update', function (\$validated_data, \$supplier, \$request) { return \$validated_data; });",
            ],

            // ========================================
            // DASHBOARD FILTERS
            // ========================================
            'dashboard_stats_data' => [
                'description' => 'Modify dashboard statistics',
                'parameters' => ['$stats', '$user'],
                'example' => "add_filter('dashboard_stats_data', function (\$stats, \$user) { \$stats['custom_metric'] = 100; return \$stats; });",
            ],
            'dashboard_page_data' => [
                'description' => 'Modify all props passed to the dashboard page',
                'parameters' => ['$data', '$user'],
                'example' => "add_filter('dashboard_page_data', function (\$data, \$user) { return \$data; });",
            ],

            // ========================================
            // EMAIL FILTERS
            // ========================================
            'email_notification_data' => [
                'description' => 'Modify the data passed to a notification email',
                'parameters' => ['$data', '$type', '$user'],
                'example' => "add_filter('email_notification_data', function (\$data, \$type, \$user) { return \$data; });",
            ],
            'should_send_email' => [
                'description' => 'Return false to stop a notification email from being sent',
                'parameters' => ['$should_send', '$type', '$user', '$data'],
                'example' => "add_filter('should_send_email', function (\$should_send, \$type, \$user, \$data) { return \$should_send; });",
            ],
            'email_mailable_class' => [
                'description' => 'Supply a Mailable class for a custom notification type',
                'parameters' => ['$mailable_class', '$type', '$data'],
                'example' => "add_filter('email_mailable_class', function (\$mailable_class, \$type, \$data) { return \$mailable_class; });",
            ],

            // ========================================
            // REPORT FILTERS
            // ========================================
            'report_data_sources' => [
                'description' => 'Register extra report builder data sources',
                'parameters' => ['$sources'],
                'example' => "add_filter('report_data_sources', function (\$sources) { \$sources['my_source'] = ['label' => 'My source', 'columns' => []]; return \$sources; });",
            ],
            'report_query_{source}' => [
                'description' => 'Return the rows (a Collection) for a plugin-registered report data source',
                'parameters' => ['$rows', '$organization_id', '$columns', '$filters', '$sort'],
                'example' => "add_filter('report_query_my_source', function (\$rows, \$organization_id, \$columns, \$filters, \$sort) { return collect(); });",
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
