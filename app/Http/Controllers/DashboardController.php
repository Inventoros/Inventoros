<?php

namespace App\Http\Controllers;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // Get statistics
        $stats = [
            'totalProducts' => Product::where('organization_id', $user->organization_id)->count(),
            'totalValue' => Product::where('organization_id', $user->organization_id)->sum('price'),
            'lowStockProducts' => Product::where('organization_id', $user->organization_id)
                ->whereColumn('stock', '<=', 'min_stock')
                ->count(),
            'categories' => ProductCategory::where('organization_id', $user->organization_id)->count(),
            'locations' => ProductLocation::where('organization_id', $user->organization_id)->count(),
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

        // Get stock value by category
        $stockByCategory = ProductCategory::where('organization_id', $user->organization_id)
            ->withSum(['products' => function ($query) use ($user) {
                $query->where('organization_id', $user->organization_id);
            }], 'price')
            ->withCount(['products' => function ($query) use ($user) {
                $query->where('organization_id', $user->organization_id);
            }])
            ->get()
            ->map(function ($category) {
                return [
                    'name' => $category->name,
                    'value' => $category->products_sum_price ?? 0,
                    'count' => $category->products_count ?? 0,
                ];
            });

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentProducts' => $recentProducts,
            'lowStockProducts' => $lowStockProducts,
            'stockByCategory' => $stockByCategory,
        ]);
    }
}
