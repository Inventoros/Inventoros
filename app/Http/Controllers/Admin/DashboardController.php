<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Http\Request;
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

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentProducts' => $recentProducts,
            'lowStockProducts' => $lowStockProducts,
            'recentOrders' => $recentOrders,
            'stockByCategory' => $stockByCategory,
        ]);
    }
}
