<?php

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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // Get statistics
        // Calculate total value as sum of (price * stock) for each product
        $totalValue = Product::where('organization_id', $user->organization_id)
            ->get()
            ->sum(function ($product) {
                return $product->price * $product->stock;
            });

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
        $stockByCategory = ProductCategory::where('organization_id', $user->organization_id)
            ->with(['products' => function ($query) use ($user) {
                $query->where('organization_id', $user->organization_id);
            }])
            ->get()
            ->map(function ($category) {
                $totalValue = $category->products->sum(function ($product) {
                    return $product->price * $product->stock;
                });

                return [
                    'name' => $category->name,
                    'value' => $totalValue,
                    'count' => $category->products->count(),
                ];
            })
            ->filter(function ($category) {
                return $category['count'] > 0;
            })
            ->values();

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
            ->get()
            ->sortByDesc(function ($product) {
                return $product->price * $product->stock;
            })
            ->take(5)
            ->map(function ($product) {
                return [
                    'name' => $product->name,
                    'value' => $product->price * $product->stock,
                    'stock' => $product->stock,
                ];
            })
            ->values();

        $data = [
            'stats' => $stats,
            'recentProducts' => $recentProducts,
            'lowStockProducts' => $lowStockProducts,
            'recentOrders' => $recentOrders,
            'stockByCategory' => $stockByCategory,
            'recentActivity' => $recentActivity,
            'stockMovements' => $stockMovements,
            'topProducts' => $topProducts,
            'pluginComponents' => [
                'header' => get_page_components('dashboard', 'header'),
                'widgets' => get_page_components('dashboard', 'widgets'),
            ],
        ];

        // Hook: Allow plugins to modify all dashboard data
        $data = apply_filters('dashboard_page_data', $data, $user);

        // Action: Dashboard viewed
        do_action('dashboard_viewed', $user);

        return Inertia::render('Dashboard', $data);
    }
}
