<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\SavedReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for generating reports.
 *
 * Handles various inventory and sales reports including
 * inventory valuation, stock movement, sales analysis,
 * low stock alerts, and category performance.
 */
class ReportController extends Controller
{
    /**
     * Display the reports dashboard.
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $savedReports = SavedReport::accessibleBy($user)
            ->with('creator:id,name')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        return Inertia::render('Reports/Index', [
            'savedReports' => $savedReports,
        ]);
    }

    /**
     * Inventory Valuation Report.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function inventoryValuation(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $products = Product::forOrganization($organizationId)
            ->with(['category', 'location'])
            ->where('is_active', true)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category' => $product->category?->name,
                    'location' => $product->location?->name,
                    'stock' => $product->stock,
                    'price' => $product->price,
                    'purchase_price' => $product->purchase_price ?? 0,
                    'stock_value' => $product->stock * $product->price,
                    'cost_value' => $product->stock * ($product->purchase_price ?? 0),
                    'profit_potential' => $product->stock * ($product->price - ($product->purchase_price ?? 0)),
                ];
            });

        $summary = [
            'total_items' => $products->count(),
            'total_quantity' => $products->sum('stock'),
            'total_stock_value' => $products->sum('stock_value'),
            'total_cost_value' => $products->sum('cost_value'),
            'total_profit_potential' => $products->sum('profit_potential'),
        ];

        // Group by category
        $byCategory = $products->groupBy('category')->map(function ($items, $category) {
            return [
                'category' => $category ?: 'Uncategorized',
                'items' => $items->count(),
                'quantity' => $items->sum('stock'),
                'value' => $items->sum('stock_value'),
            ];
        })->values();

        return Inertia::render('Reports/InventoryValuation', [
            'products' => $products,
            'summary' => $summary,
            'byCategory' => $byCategory,
        ]);
    }

    /**
     * Stock Movement Report.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function stockMovement(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAdjustment::with(['product', 'user'])
            ->forOrganization($organizationId);

        // Date filters
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Product filter
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Type filter
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $adjustments = $query->latest()->paginate(50)->withQueryString();

        // Summary statistics
        $summary = [
            'total_adjustments' => StockAdjustment::forOrganization($organizationId)->count(),
            'total_increases' => StockAdjustment::forOrganization($organizationId)
                ->where('adjustment_quantity', '>', 0)->sum('adjustment_quantity'),
            'total_decreases' => abs(StockAdjustment::forOrganization($organizationId)
                ->where('adjustment_quantity', '<', 0)->sum('adjustment_quantity')),
            'net_change' => StockAdjustment::forOrganization($organizationId)->sum('adjustment_quantity'),
        ];

        // Get products for filter
        $products = Product::forOrganization($organizationId)
            ->select('id', 'name', 'sku')
            ->orderBy('name')
            ->get();

        return Inertia::render('Reports/StockMovement', [
            'adjustments' => $adjustments,
            'summary' => $summary,
            'products' => $products,
            'filters' => $request->only(['date_from', 'date_to', 'product_id', 'type']),
        ]);
    }

    /**
     * Sales Analysis Report.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function salesAnalysis(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        // Date filters — pass through as boundary timestamps rather than
        // using whereDate so the order_date index can serve the predicate.
        $dateFrom = $request->date_from ?? now()->subDays(30)->format('Y-m-d');
        $dateTo = $request->date_to ?? now()->format('Y-m-d');
        $fromTimestamp = $dateFrom . ' 00:00:00';
        $toTimestamp = $dateTo . ' 23:59:59';

        // Each aggregate is its own SQL round-trip — previously the
        // controller hydrated every Order + nested items.product for the
        // window into memory and aggregated in PHP, which OOMs on large
        // tenants and large windows.

        // Summary
        $summary = Order::forOrganization($organizationId)
            ->whereBetween('order_date', [$fromTimestamp, $toTimestamp])
            ->selectRaw('COUNT(*) as total_orders, COALESCE(SUM(total), 0) as total_revenue')
            ->first();

        $totalOrders = (int) $summary->total_orders;
        $totalRevenue = (float) $summary->total_revenue;

        $totalItemsSold = (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $organizationId)
            ->whereBetween('orders.order_date', [$fromTimestamp, $toTimestamp])
            ->sum('order_items.quantity');

        $summary = [
            'total_orders' => $totalOrders,
            'total_revenue' => $totalRevenue,
            'total_items_sold' => $totalItemsSold,
            'average_order_value' => $totalOrders > 0 ? $totalRevenue / $totalOrders : 0,
        ];

        // Sales by status
        $byStatus = Order::forOrganization($organizationId)
            ->whereBetween('order_date', [$fromTimestamp, $toTimestamp])
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(total), 0) as revenue')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
                'revenue' => (float) $row->revenue,
            ])
            ->values();

        // Top selling products (by revenue, top 10).
        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $organizationId)
            ->whereBetween('orders.order_date', [$fromTimestamp, $toTimestamp])
            ->selectRaw('
                order_items.product_id,
                order_items.product_name,
                order_items.sku,
                SUM(order_items.quantity) as quantity_sold,
                SUM(order_items.total) as revenue
            ')
            ->groupBy('order_items.product_id', 'order_items.product_name', 'order_items.sku')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'quantity_sold' => (int) $row->quantity_sold,
                'revenue' => (float) $row->revenue,
            ])
            ->values();

        // Daily sales trend
        $dailySales = Order::forOrganization($organizationId)
            ->whereBetween('order_date', [$fromTimestamp, $toTimestamp])
            ->selectRaw('DATE(order_date) as date, COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
            ])
            ->values();

        return Inertia::render('Reports/SalesAnalysis', [
            'summary' => $summary,
            'byStatus' => $byStatus,
            'topProducts' => $topProducts,
            'dailySales' => $dailySales,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Low Stock Report.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function lowStock(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $products = Product::forOrganization($organizationId)
            ->with(['category', 'location'])
            ->where('is_active', true)
            ->whereRaw('stock <= min_stock')
            ->orderBy('stock', 'asc')
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category' => $product->category?->name,
                    'location' => $product->location?->name,
                    'current_stock' => $product->stock,
                    'min_stock' => $product->min_stock,
                    'max_stock' => $product->max_stock,
                    'deficit' => $product->min_stock - $product->stock,
                    'status' => $product->stock <= 0 ? 'out_of_stock' : 'low_stock',
                    'price' => $product->price,
                    'reorder_cost' => ($product->max_stock - $product->stock) * ($product->purchase_price ?? $product->price),
                ];
            });

        $summary = [
            'total_low_stock' => $products->count(),
            'out_of_stock' => $products->where('status', 'out_of_stock')->count(),
            'low_stock' => $products->where('status', 'low_stock')->count(),
            'total_reorder_cost' => $products->sum('reorder_cost'),
        ];

        return Inertia::render('Reports/LowStock', [
            'products' => $products,
            'summary' => $summary,
        ]);
    }

    /**
     * Category Performance Report.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function categoryPerformance(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        // Get all products grouped by category
        $products = Product::forOrganization($organizationId)
            ->with('category')
            ->where('is_active', true)
            ->get()
            ->groupBy('category_id');

        $categoryStats = $products->map(function ($items, $categoryId) {
            $category = $items->first()->category;
            return [
                'category_id' => $categoryId,
                'category_name' => $category?->name ?? 'Uncategorized',
                'product_count' => $items->count(),
                'total_stock' => $items->sum('stock'),
                'total_value' => $items->sum(function ($p) {
                    return $p->stock * $p->price;
                }),
                'low_stock_items' => $items->filter(function ($p) {
                    return $p->stock <= $p->min_stock;
                })->count(),
            ];
        })->values()->sortByDesc('total_value');

        return Inertia::render('Reports/CategoryPerformance', [
            'categories' => $categoryStats,
            'summary' => [
                'total_categories' => $categoryStats->count(),
                'total_products' => $categoryStats->sum('product_count'),
                'total_value' => $categoryStats->sum('total_value'),
            ],
        ]);
    }
}
