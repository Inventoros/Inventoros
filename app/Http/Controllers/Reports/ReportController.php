<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\SavedReport;
use App\Services\ReceivablesAgingService;
use App\Services\ReorderService;
use App\Services\Reports\InventoryAnalyticsService;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportPeriod;
use App\Services\WarehouseAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Controller for generating reports.
 *
 * Handles various inventory and sales reports including
 * inventory valuation, stock movement, sales analysis,
 * low stock alerts, and category performance.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportExporter $exporter,
        private readonly InventoryAnalyticsService $analytics,
    ) {}

    /**
     * Display the reports dashboard.
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
     * Totals and the by-category / by-location breakdowns are SQL aggregates
     * over every active product; the product list is capped at
     * reports.max_rows (highest stock value first) so a large catalogue is
     * never hydrated into memory. ?export=csv|xlsx|pdf downloads the product
     * list, or the breakdown named by ?group=category|location.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function inventoryValuation(Request $request): Response|HttpResponse
    {
        $organizationId = $request->user()->organization_id;
        $maxRows = $this->analytics->maxRows();

        $base = fn () => DB::table('products')
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true);

        $totals = $base()->selectRaw('
            COUNT(*) as total_items,
            COALESCE(SUM(stock), 0) as total_quantity,
            COALESCE(SUM(stock * price), 0) as total_stock_value,
            COALESCE(SUM(stock * COALESCE(purchase_price, 0)), 0) as total_cost_value,
            COALESCE(SUM(stock * (price - COALESCE(purchase_price, 0))), 0) as total_profit_potential
        ')->first();

        $summary = [
            'total_items' => (int) $totals->total_items,
            'total_quantity' => (int) $totals->total_quantity,
            'total_stock_value' => round((float) $totals->total_stock_value, 2),
            'total_cost_value' => round((float) $totals->total_cost_value, 2),
            'total_profit_potential' => round((float) $totals->total_profit_potential, 2),
        ];

        $products = $base()
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('product_locations', 'product_locations.id', '=', 'products.location_id')
            ->selectRaw('
                products.id, products.name, products.sku, products.stock, products.price, products.purchase_price,
                product_categories.name as category, product_locations.name as location,
                (products.stock * products.price) as stock_value
            ')
            ->orderByDesc('stock_value')
            ->orderBy('products.id')
            ->limit($maxRows)
            ->get()
            ->map(function ($product) {
                $price = (float) $product->price;
                $cost = (float) ($product->purchase_price ?? 0);
                $stock = (int) $product->stock;

                return [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category' => $product->category,
                    'location' => $product->location,
                    'stock' => $stock,
                    'price' => $price,
                    'purchase_price' => $cost,
                    'stock_value' => round($stock * $price, 2),
                    'cost_value' => round($stock * $cost, 2),
                    'profit_potential' => round($stock * ($price - $cost), 2),
                ];
            });

        $byCategory = $base()
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->groupBy('products.category_id', 'product_categories.name')
            ->selectRaw('
                products.category_id as category_id,
                product_categories.name as category,
                COUNT(*) as items,
                COALESCE(SUM(products.stock), 0) as quantity,
                COALESCE(SUM(products.stock * products.price), 0) as value
            ')
            ->orderByDesc('value')
            ->limit($maxRows)
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category' => $row->category ?: 'Uncategorized',
                'items' => (int) $row->items,
                'quantity' => (int) $row->quantity,
                'value' => round((float) $row->value, 2),
            ])
            ->values();

        // Per-location stock follows warehouse access (#224).
        $byLocation = $this->analytics->valuationByLocation(
            $organizationId,
            app(WarehouseAccessService::class)->accessibleWarehouseIds($request->user())
        );

        if ($format = ReportExporter::requestedFormat($request)) {
            return match ($request->query('group')) {
                'category' => $this->exporter->download(
                    $format,
                    'Inventory Valuation by Category',
                    ['Category', 'Products', 'Quantity', 'Stock value'],
                    $byCategory->map(fn (array $r) => [$r['category'], $r['items'], $r['quantity'], $r['value']])
                ),
                'location' => $this->exporter->download(
                    $format,
                    'Inventory Valuation by Location',
                    ['Location', 'Warehouse', 'Products', 'Quantity', 'Cost value', 'Retail value'],
                    array_map(fn (array $r) => [
                        $r['location'] ?? 'Unallocated', $r['warehouse'], $r['products'], $r['quantity'], $r['cost_value'], $r['retail_value'],
                    ], $byLocation)
                ),
                default => $this->exporter->download(
                    $format,
                    'Inventory Valuation',
                    ['Product', 'SKU', 'Category', 'Location', 'Stock', 'Price', 'Purchase price', 'Stock value', 'Cost value', 'Profit potential'],
                    $products->map(fn (array $r) => [
                        $r['name'], $r['sku'], $r['category'], $r['location'], $r['stock'], $r['price'],
                        $r['purchase_price'], $r['stock_value'], $r['cost_value'], $r['profit_potential'],
                    ])
                ),
            };
        }

        return Inertia::render('Reports/InventoryValuation', [
            'products' => $products,
            'summary' => $summary,
            'byCategory' => $byCategory,
            'byLocation' => $byLocation,
            'truncated' => $summary['total_items'] > $products->count(),
        ]);
    }

    /**
     * Stock Movement Report.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function stockMovement(Request $request): Response|HttpResponse
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

        // Summary statistics over the SAME filtered set as the table (clone so
        // each aggregate gets its own builder). Previously these ignored the
        // date/product/type filters, so the headline totals contradicted the
        // rows shown.
        $summary = [
            'total_adjustments' => (clone $query)->count(),
            // SUM() comes back as a numeric string on MySQL/PostgreSQL.
            'total_increases' => (int) (clone $query)->where('adjustment_quantity', '>', 0)->sum('adjustment_quantity'),
            'total_decreases' => abs((int) (clone $query)->where('adjustment_quantity', '<', 0)->sum('adjustment_quantity')),
            'net_change' => (int) (clone $query)->sum('adjustment_quantity'),
        ];

        if ($format = ReportExporter::requestedFormat($request)) {
            return $this->exporter->download(
                $format,
                'Stock Movement',
                ['Date', 'Product', 'SKU', 'Type', 'Before', 'Change', 'After', 'Reason', 'User'],
                (clone $query)->latest()->limit($this->analytics->maxRows())->get()->map(fn (StockAdjustment $a) => [
                    $a->created_at?->format('Y-m-d H:i'),
                    $a->product?->name,
                    $a->product?->sku,
                    $a->type,
                    (int) $a->quantity_before,
                    (int) $a->adjustment_quantity,
                    (int) $a->quantity_after,
                    $a->reason,
                    $a->user?->name,
                ])
            );
        }

        $adjustments = $query->latest()->paginate(50)->withQueryString();

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
     * @param  Request  $request  The incoming HTTP request
     */
    public function salesAnalysis(Request $request): Response|HttpResponse
    {
        $organizationId = $request->user()->organization_id;

        // Date filters — pass through as boundary timestamps rather than
        // using whereDate so the order_date index can serve the predicate.
        // Parsed and validated (malformed dates fall back to the last 30
        // days) so the same period drives the comparison below.
        $period = ReportPeriod::fromRequest($request);
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = $period->toFilters();
        $fromTimestamp = $dateFrom.' 00:00:00';
        $toTimestamp = $dateTo.' 23:59:59';

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

        // Payment position of the window's orders, only for users who may see
        // payments (absent, not zeroed, for everyone else).
        $byPaymentStatus = null;
        if ($request->user()->hasPermission(Permission::VIEW_PAYMENTS)) {
            $rows = Order::forOrganization($organizationId)
                ->whereBetween('order_date', [$fromTimestamp, $toTimestamp])
                ->where('status', '!=', 'cancelled')
                ->selectRaw('payment_status, COUNT(*) as count, COALESCE(SUM(total), 0) as total, COALESCE(SUM(amount_paid), 0) as amount_paid, COALESCE(SUM(CASE WHEN total > amount_paid AND payment_status <> ? THEN total - amount_paid ELSE 0 END), 0) as balance_due', [PaymentStatus::UNTRACKED->value])
                ->groupBy('payment_status')
                ->get()
                ->keyBy(fn ($row) => $row->payment_status instanceof PaymentStatus ? $row->payment_status->value : (string) $row->payment_status);

            $byPaymentStatus = collect(PaymentStatus::cases())
                ->filter(fn (PaymentStatus $status) => $rows->has($status->value))
                ->map(fn (PaymentStatus $status) => [
                    'payment_status' => $status->value,
                    'count' => (int) $rows[$status->value]->count,
                    'total' => round((float) $rows[$status->value]->total, 2),
                    'amount_paid' => round((float) $rows[$status->value]->amount_paid, 2),
                    'balance_due' => round((float) $rows[$status->value]->balance_due, 2),
                ])
                ->values();

            $summary['total_outstanding'] = round((float) $byPaymentStatus->sum('balance_due'), 2);
        }

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

        // Period-over-period: the same headline figures for the equal-length
        // period immediately before, with the change in percent (null when
        // the previous period had nothing to compare against).
        $previousPeriod = $period->previous();
        $current = $this->analytics->salesSummary($organizationId, $period);
        $previous = $this->analytics->salesSummary($organizationId, $previousPeriod);
        $delta = [];
        foreach ($current as $key => $value) {
            $delta[$key] = InventoryAnalyticsService::deltaPct((float) $value, (float) $previous[$key]);
        }
        $comparison = [
            'previousPeriod' => $previousPeriod->toFilters(),
            'previous' => $previous,
            'delta' => $delta,
        ];

        if ($format = ReportExporter::requestedFormat($request)) {
            $notes = ['Period: '.$period->label().'.'];

            return match ($request->query('group')) {
                'status' => $this->exporter->download(
                    $format,
                    'Sales by Status',
                    ['Status', 'Orders', 'Revenue'],
                    $byStatus->map(fn (array $r) => [
                        $r['status'] instanceof \BackedEnum ? $r['status']->value : (string) $r['status'], $r['count'], $r['revenue'],
                    ]),
                    $notes
                ),
                'products' => $this->exporter->download(
                    $format,
                    'Top Selling Products',
                    ['Product', 'SKU', 'Units sold', 'Revenue'],
                    $topProducts->map(fn (array $r) => [$r['product_name'], $r['sku'], $r['quantity_sold'], $r['revenue']]),
                    $notes
                ),
                default => $this->exporter->download(
                    $format,
                    'Sales Analysis',
                    ['Date', 'Orders', 'Revenue'],
                    $dailySales->map(fn (array $r) => [(string) $r['date'], $r['orders'], $r['revenue']]),
                    $notes
                ),
            };
        }

        return Inertia::render('Reports/SalesAnalysis', array_filter([
            'summary' => $summary,
            'byStatus' => $byStatus,
            'byPaymentStatus' => $byPaymentStatus,
            'topProducts' => $topProducts,
            'dailySales' => $dailySales,
            'comparison' => $comparison,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ], fn ($value) => $value !== null));
    }

    /**
     * Outstanding balances (accounts receivable aging).
     *
     * Route-gated by view_reports and view_payments.
     */
    public function receivables(Request $request, ReceivablesAgingService $aging): Response
    {
        return Inertia::render('Reports/Receivables', $aging->build($request->user()->organization_id) + [
            'currency' => $request->user()->organization?->currency ?? 'USD',
        ]);
    }

    /**
     * Low Stock Report.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function lowStock(Request $request): Response|HttpResponse
    {
        $organizationId = $request->user()->organization_id;

        $reorder = app(ReorderService::class);

        $products = Product::forOrganization($organizationId)
            ->with(array_merge(['category', 'location'], ReorderService::primarySupplierEagerLoad()))
            ->where('is_active', true)
            // Low in total, or low in a warehouse with its own minimum.
            ->lowStock()
            ->orderBy('stock', 'asc')
            ->get()
            ->map(function ($product) use ($reorder) {
                $primarySupplier = $reorder->primarySupplier($product);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category' => $product->category?->name,
                    'location' => $product->location?->name,
                    'current_stock' => $product->stock,
                    'min_stock' => $product->min_stock,
                    'max_stock' => $product->max_stock,
                    'deficit' => max(0, $product->min_stock - $product->stock),
                    'status' => $product->stock <= 0 ? 'out_of_stock' : 'low_stock',
                    'price' => $product->price,
                    'supplier' => $primarySupplier?->name,
                    'supplier_id' => $primarySupplier?->id,
                    'suggested_quantity' => $reorder->suggestedQuantity($product, $primarySupplier),
                    // Reorder up to max_stock; fall back to reorder_point /
                    // min_stock when it's null, and never let a null or
                    // already-satisfied target produce a negative cost.
                    'reorder_cost' => max(0, ($product->max_stock ?? $product->reorder_point ?? $product->min_stock ?? 0) - $product->stock)
                        * ($product->purchase_price ?? $product->price),
                ];
            });

        $summary = [
            'total_low_stock' => $products->count(),
            'out_of_stock' => $products->where('status', 'out_of_stock')->count(),
            'low_stock' => $products->where('status', 'low_stock')->count(),
            'total_reorder_cost' => $products->sum('reorder_cost'),
        ];

        if ($format = ReportExporter::requestedFormat($request)) {
            return $this->exporter->download(
                $format,
                'Low Stock',
                ['Product', 'SKU', 'Category', 'Location', 'Current stock', 'Min stock', 'Max stock', 'Deficit', 'Status', 'Supplier', 'Suggested quantity', 'Reorder cost'],
                $products->take($this->analytics->maxRows())->map(fn (array $r) => [
                    $r['name'], $r['sku'], $r['category'], $r['location'], (int) $r['current_stock'], (int) $r['min_stock'],
                    $r['max_stock'] === null ? null : (int) $r['max_stock'], (int) $r['deficit'], $r['status'],
                    $r['supplier'], $r['suggested_quantity'] === null ? null : (int) $r['suggested_quantity'], (float) $r['reorder_cost'],
                ])
            );
        }

        return Inertia::render('Reports/LowStock', [
            'products' => $products,
            'summary' => $summary,
        ]);
    }

    /**
     * Category Performance Report.
     *
     * One GROUP BY over active products; ?export=csv|xlsx|pdf downloads it.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function categoryPerformance(Request $request): Response|HttpResponse
    {
        $organizationId = $request->user()->organization_id;

        $categoryStats = DB::table('products')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->groupBy('products.category_id', 'product_categories.name')
            ->selectRaw('
                products.category_id as category_id,
                product_categories.name as category_name,
                COUNT(*) as product_count,
                COALESCE(SUM(products.stock), 0) as total_stock,
                COALESCE(SUM(products.stock * products.price), 0) as total_value,
                SUM(CASE WHEN products.stock <= products.min_stock THEN 1 ELSE 0 END) as low_stock_items
            ')
            ->orderByDesc('total_value')
            ->limit($this->analytics->maxRows())
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category_name' => $row->category_name ?? 'Uncategorized',
                'product_count' => (int) $row->product_count,
                'total_stock' => (int) $row->total_stock,
                'total_value' => round((float) $row->total_value, 2),
                'low_stock_items' => (int) $row->low_stock_items,
            ])
            ->values();

        if ($format = ReportExporter::requestedFormat($request)) {
            return $this->exporter->download(
                $format,
                'Category Performance',
                ['Category', 'Products', 'Total stock', 'Total value', 'Low stock items'],
                $categoryStats->map(fn (array $r) => [
                    $r['category_name'], $r['product_count'], $r['total_stock'], $r['total_value'], $r['low_stock_items'],
                ])
            );
        }

        return Inertia::render('Reports/CategoryPerformance', [
            'categories' => $categoryStats,
            'summary' => [
                'total_categories' => $categoryStats->count(),
                'total_products' => $categoryStats->sum('product_count'),
                'total_value' => round((float) $categoryStats->sum('total_value'), 2),
            ],
        ]);
    }
}
