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
use App\Services\PluginUIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

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
     * @return \Inertia\Response
     */
    public function index()
    {
        $user = auth()->user();

        // Get statistics
        // Calculate total value as sum of (price * stock) for each product
        $totalValue = Product::where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->selectRaw('COALESCE(SUM(price * stock), 0) as total')
            ->value('total');

        // Get order statistics
        $totalOrders = Order::where('organization_id', $user->organization_id)->count();
        $pendingOrders = Order::where('organization_id', $user->organization_id)
            ->where('status', 'pending')
            ->count();
        $revenueThisMonth = Order::where('organization_id', $user->organization_id)
            ->whereMonth('order_date', now()->month)
            ->whereYear('order_date', now()->year)
            ->sum('total');

        $stats = [
            'totalProducts' => Product::where('organization_id', $user->organization_id)->count(),
            'totalValue' => $totalValue,
            'lowStockProducts' => Product::where('organization_id', $user->organization_id)
                ->whereColumn('stock', '<=', 'min_stock')
                ->count(),
            'categories' => ProductCategory::where('organization_id', $user->organization_id)->count(),
            'locations' => ProductLocation::where('organization_id', $user->organization_id)->count(),
            'totalOrders' => $totalOrders,
            'pendingOrders' => $pendingOrders,
            'revenueThisMonth' => $revenueThisMonth,
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
            ->leftJoin('products', function($join) {
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
                    'user' => $log->user->name,
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
     * @param Request $request The incoming HTTP request
     * @return JsonResponse
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

        if (!empty($invalidKeys)) {
            return response()->json([
                'message' => 'Invalid widget keys: ' . implode(', ', $invalidKeys),
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
