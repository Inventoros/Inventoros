<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the admin dashboard.
 *
 * Displays main dashboard with statistics, recent activity,
 * and plugin integration points.
 */
class DashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     *
     * @return Response
     */
    public function index()
    {
        $user = auth()->user();
        $orgId = $user->organization_id;

        // Consolidate the five product-level aggregates (count, active stock
        // value, low-stock count) into one selectRaw round-trip and the three
        // order-level aggregates (count, pending count, month revenue) into
        // another. Categories and locations stay as separate counts because
        // they live in different tables.
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $productAgg = Product::where('organization_id', $orgId)
            ->selectRaw('
                COUNT(*) as total_count,
                COALESCE(SUM(CASE WHEN is_active THEN price * stock ELSE 0 END), 0) as total_value,
                SUM(CASE WHEN stock <= min_stock THEN 1 ELSE 0 END) as low_stock_count
            ')
            ->first();

        $orderAgg = Order::where('organization_id', $orgId)
            ->selectRaw('
                COUNT(*) as total_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count,
                COALESCE(SUM(CASE WHEN order_date >= ? AND order_date <= ? THEN total ELSE 0 END), 0) as month_revenue
            ', ['pending', $monthStart, $monthEnd])
            ->first();

        $stats = [
            'totalProducts' => (int) ($productAgg->total_count ?? 0),
            'totalValue' => (float) ($productAgg->total_value ?? 0),
            'lowStockProducts' => (int) ($productAgg->low_stock_count ?? 0),
            'categories' => ProductCategory::where('organization_id', $orgId)->count(),
            'locations' => ProductLocation::where('organization_id', $orgId)->count(),
            'totalOrders' => (int) ($orderAgg->total_count ?? 0),
            'pendingOrders' => (int) ($orderAgg->pending_count ?? 0),
            'revenueThisMonth' => (float) ($orderAgg->month_revenue ?? 0),
        ];

        // Hook: Allow plugins to modify stats
        $stats = apply_filters('dashboard_stats_data', $stats, $user);

        // Action: Stats calculated
        do_action('dashboard_stats_calculated', $stats, $user);

        // Get recent products
        $recentProducts = Product::where('organization_id', $user->organization_id)
            ->with(['category', 'location'])
            ->latest()
            ->limit(5)
            ->get();

        // Get low stock products
        $lowStockProducts = Product::where('organization_id', $user->organization_id)
            ->whereColumn('stock', '<=', 'min_stock')
            ->with(['category', 'location'])
            ->orderBy('stock', 'asc')
            ->limit(5)
            ->get();

        // Get recent orders
        $recentOrders = Order::where('organization_id', $user->organization_id)
            ->with('items')
            ->latest('order_date')
            ->limit(5)
            ->get();

        // Get stock value by category
        $stockByCategory = ProductCategory::where('product_categories.organization_id', $user->organization_id)
            ->leftJoin('products', function ($join) {
                $join->on('products.category_id', '=', 'product_categories.id')
                    ->where('products.is_active', true);
            })
            ->selectRaw('product_categories.name, product_categories.id, COALESCE(SUM(products.price * products.stock), 0) as value, COUNT(products.id) as count')
            ->groupBy('product_categories.id', 'product_categories.name')
            ->get();

        // Get recent activity logs
        $recentActivity = ActivityLog::where('organization_id', $user->organization_id)
            ->with('user')
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    // The acting user may have been deleted (or be a system
                    // action with no user); don't 500 the whole dashboard.
                    'user' => $log->user?->name ?? 'System',
                    'action' => $log->action,
                    'description' => $log->description,
                    'created_at' => $log->created_at->diffForHumans(),
                ];
            });

        // Get reorder suggestions (products below reorder point)
        $reorderSuggestions = Product::where('organization_id', $user->organization_id)
            ->needsReorder()
            ->with(['category', 'suppliers' => function ($query) {
                $query->wherePivot('is_primary', true);
            }])
            ->orderBy('stock', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($product) {
                $primarySupplier = $product->suppliers->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => $product->stock,
                    'reorder_point' => $product->reorder_point,
                    'reorder_quantity' => $product->reorder_quantity,
                    'category' => $product->category?->name,
                    'supplier' => $primarySupplier?->name,
                ];
            });

        // Get stock movements (last 7 days)
        $stockMovements = StockAdjustment::where('organization_id', $user->organization_id)
            ->where('created_at', '>=', now()->subDays(7))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(adjustment_quantity) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($movement) {
                return [
                    'date' => $movement->date,
                    'total' => $movement->total,
                ];
            });

        // Get top products by value
        $topProducts = Product::where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->selectRaw('id, name, sku, price, stock, (price * stock) as total_value')
            ->orderByRaw('price * stock DESC')
            ->limit(5)
            ->get();

        // Get widget preferences (default: all visible)
        $defaultWidgets = [
            'stats_overview' => true,
            'revenue_chart' => true,
            'stock_movements' => true,
            'low_stock_alerts' => true,
            'recent_orders' => true,
            'recent_products' => true,
            'top_products' => true,
            'stock_by_category' => true,
            'reorder_suggestions' => true,
        ];
        $widgetPreferences = $user->dashboard_widgets ?? $defaultWidgets;
        // Merge with defaults to ensure any new widgets are visible by default
        $widgetPreferences = array_merge($defaultWidgets, $widgetPreferences);

        $data = [
            'stats' => $stats,
            'recentProducts' => $recentProducts,
            'lowStockProducts' => $lowStockProducts,
            'reorderSuggestions' => $reorderSuggestions,
            'recentOrders' => $recentOrders,
            'stockByCategory' => $stockByCategory,
            'recentActivity' => $recentActivity,
            'stockMovements' => $stockMovements,
            'topProducts' => $topProducts,
            'widgetPreferences' => $widgetPreferences,
            'pluginComponents' => [
                'header' => get_page_components('dashboard', 'header'),
                'beforeStats' => get_page_components('dashboard', 'before-stats'),
                'afterStats' => get_page_components('dashboard', 'after-stats'),
                'beforeContent' => get_page_components('dashboard', 'before-content'),
                'afterContent' => get_page_components('dashboard', 'after-content'),
                'widgets' => get_page_components('dashboard', 'widgets'),
                'footer' => get_page_components('dashboard', 'footer'),
            ],
        ];

        // Hook: Allow plugins to modify all dashboard data
        $data = apply_filters('dashboard_page_data', $data, $user);

        // Action: Dashboard viewed
        do_action('dashboard_viewed', $user);

        return Inertia::render('Dashboard', $data);
    }

    /**
     * Update the user's dashboard widget preferences.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function updateWidgets(Request $request): JsonResponse
    {
        $validWidgetKeys = [
            'stats_overview',
            'revenue_chart',
            'stock_movements',
            'low_stock_alerts',
            'recent_orders',
            'recent_products',
            'top_products',
            'stock_by_category',
            'reorder_suggestions',
        ];

        $request->validate([
            'widgets' => ['required', 'array'],
            'widgets.*' => ['boolean'],
        ]);

        // Ensure only valid widget keys are present
        $submittedKeys = array_keys($request->input('widgets'));
        $invalidKeys = array_diff($submittedKeys, $validWidgetKeys);

        if (! empty($invalidKeys)) {
            return response()->json([
                'message' => 'Invalid widget keys: '.implode(', ', $invalidKeys),
                'errors' => ['widgets' => ['Contains invalid widget keys.']],
            ], 422);
        }

        $user = $request->user();
        $user->update([
            'dashboard_widgets' => $request->input('widgets'),
        ]);

        return response()->json(['success' => true]);
    }
}
