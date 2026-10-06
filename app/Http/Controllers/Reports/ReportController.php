<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
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
use App\Services\WarehouseStockLevelService;
use App\Support\CurrencyTotals;
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

        // Money is totalled per product currency and never added across
        // currencies: the headline figures are the organization's currency,
        // by_currency lists every currency (that one first).
        $currency = Organization::currencyFor($organizationId);
        $byCurrency = CurrencyTotals::breakdown(
            // A product sold by variant counts its active variants, each at
            // its own price and cost.
            $base()->groupBy('currency')->selectRaw('
                currency,
                COUNT(*) as items,
                COALESCE(SUM('.Product::effectiveStockSql().'), 0) as quantity,
                COALESCE(SUM('.Product::stockValueSql('price').'), 0) as stock_value,
                COALESCE(SUM('.Product::stockValueSql('purchase_price').'), 0) as cost_value,
                COALESCE(SUM('.Product::stockValueSql('price').' - '.Product::stockValueSql('purchase_price').'), 0) as profit_potential
            ')->get(),
            $currency,
            ['stock_value', 'cost_value', 'profit_potential'],
            ['items', 'quantity'],
        );

        $summary = [
            'total_items' => array_sum(array_column($byCurrency, 'items')),
            'total_quantity' => array_sum(array_column($byCurrency, 'quantity')),
            'currency' => $currency,
            'total_stock_value' => $byCurrency[0]['stock_value'],
            'total_cost_value' => $byCurrency[0]['cost_value'],
            'total_profit_potential' => $byCurrency[0]['profit_potential'],
            'by_currency' => $byCurrency,
        ];

        $products = $base()
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('product_locations', 'product_locations.id', '=', 'products.location_id')
            ->selectRaw('
                products.id, products.name, products.sku, '.Product::effectiveStockSql().' as stock, products.price, products.purchase_price,
                products.currency,
                product_categories.name as category, product_locations.name as location,
                '.Product::stockValueSql('price').' as stock_value,
                '.Product::stockValueSql('purchase_price').' as cost_value
            ')
            ->orderByDesc('stock_value')
            ->orderBy('products.id')
            ->limit($maxRows)
            ->get()
            ->map(function ($product) use ($currency) {
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
                    // Price, cost and values are in the product's currency.
                    'currency' => filled($product->currency) ? strtoupper(trim((string) $product->currency)) : $currency,
                    'price' => $price,
                    'purchase_price' => $cost,
                    // Values come from the query: a variant product's
                    // variants each count at their own price and cost.
                    'stock_value' => round((float) $product->stock_value, 2),
                    'cost_value' => round((float) $product->cost_value, 2),
                    'profit_potential' => round((float) $product->stock_value - (float) $product->cost_value, 2),
                ];
            });

        // One row per category and product currency (exports list these
        // as they are); the page gets one row per category whose value is in
        // the organization's currency, with every currency under `values`.
        $categoryCurrencyRows = $base()
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->groupBy('products.category_id', 'product_categories.name', 'products.currency')
            ->selectRaw('
                products.category_id as category_id,
                product_categories.name as category,
                products.currency as currency,
                COUNT(*) as items,
                COALESCE(SUM('.Product::effectiveStockSql().'), 0) as quantity,
                COALESCE(SUM('.Product::stockValueSql('price').'), 0) as value
            ')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category' => $row->category ?: 'Uncategorized',
                'currency' => filled($row->currency) ? strtoupper(trim((string) $row->currency)) : $currency,
                'items' => (int) $row->items,
                'quantity' => (int) $row->quantity,
                'value' => round((float) $row->value, 2),
            ]);

        $byCategory = $categoryCurrencyRows
            ->groupBy(fn (array $r) => $r['category_id'] ?? 'none')
            ->map(function ($rows) use ($currency) {
                $values = CurrencyTotals::list($rows->pluck('value', 'currency'), $currency);

                return [
                    'category_id' => $rows->first()['category_id'],
                    'category' => $rows->first()['category'],
                    'items' => $rows->sum('items'),
                    'quantity' => $rows->sum('quantity'),
                    'value' => $values[0]['amount'],
                    'values' => $values,
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['value'], $a['category']] <=> [$a['value'], $b['category']])
            ->take($maxRows)
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
                    ['Category', 'Products', 'Quantity', 'Currency', 'Stock value'],
                    $categoryCurrencyRows
                        ->sortBy([['category', 'asc'], ['currency', 'asc']])
                        ->map(fn (array $r) => [$r['category'], $r['items'], $r['quantity'], $r['currency'], $r['value']])
                        ->values()
                ),
                'location' => $this->exporter->download(
                    $format,
                    'Inventory Valuation by Location',
                    ['Location', 'Warehouse', 'Products', 'Quantity', 'Currency', 'Cost value', 'Retail value'],
                    collect($byLocation)->flatMap(fn (array $r) => array_map(fn (array $v) => [
                        $r['location'] ?? 'Unallocated', $r['warehouse'], $v['products'], $v['quantity'],
                        $v['currency'], $v['cost_value'], $v['retail_value'],
                    ], $r['values']))->all()
                ),
                default => $this->exporter->download(
                    $format,
                    'Inventory Valuation',
                    ['Product', 'SKU', 'Category', 'Location', 'Stock', 'Currency', 'Price', 'Purchase price', 'Stock value', 'Cost value', 'Profit potential'],
                    $products->map(fn (array $r) => [
                        $r['name'], $r['sku'], $r['category'], $r['location'], $r['stock'], $r['currency'], $r['price'],
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

        // Money is never added across currencies (like receivables and
        // valuation): each headline figure is the organization's currency,
        // and every row carries its per-currency breakdown (by_currency, that
        // currency first) for the page to list the others. Counts cover every
        // currency.
        $currency = Organization::currencyFor($organizationId);
        $orders = fn () => Order::forOrganization($organizationId)
            ->whereBetween('order_date', [$fromTimestamp, $toTimestamp])
            ->toBase();
        // A cancelled order is not a sale: the orders, revenue, items sold,
        // top products, daily trend and comparison leave it out, as the
        // payment position and the analytics reports do. Sales by status
        // still lists it.
        $sales = fn () => $orders()->where('status', '!=', 'cancelled');

        $byCurrency = array_map(
            fn (array $row) => $row + ['average_order_value' => $row['orders'] > 0 ? round($row['revenue'] / $row['orders'], 2) : 0.0],
            CurrencyTotals::breakdown(
                $sales()->selectRaw('currency, COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')->groupBy('currency')->get(),
                $currency,
                ['revenue'],
                ['orders'],
            ),
        );

        $totalItemsSold = (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $organizationId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.order_date', [$fromTimestamp, $toTimestamp])
            ->sum('order_items.quantity');

        $summary = [
            'total_orders' => array_sum(array_column($byCurrency, 'orders')),
            'currency' => $currency,
            'total_revenue' => $byCurrency[0]['revenue'],
            'total_items_sold' => $totalItemsSold,
            'average_order_value' => $byCurrency[0]['average_order_value'],
            'by_currency' => $byCurrency,
        ];

        // One row per $key: counts over every currency, money in the
        // organization's currency, and the per-currency split in by_currency.
        $perCurrency = function ($rows, string $key, array $money, array $counts) use ($currency) {
            return collect($rows)
                ->groupBy(fn ($row) => (string) $row->{$key})
                ->map(function ($group, $value) use ($key, $money, $counts, $currency) {
                    $split = CurrencyTotals::breakdown($group, $currency, $money, $counts);
                    $row = [$key => $value];
                    foreach ($counts as $field) {
                        $row[$field] = array_sum(array_column($split, $field));
                    }
                    foreach ($money as $field) {
                        $row[$field] = $split[0][$field];
                    }

                    return $row + ['by_currency' => $split];
                })
                ->values();
        };

        // Payment position of the window's orders, only for users who may see
        // payments (absent, not zeroed, for everyone else).
        $byPaymentStatus = null;
        if ($request->user()->hasPermission(Permission::VIEW_PAYMENTS)) {
            $rows = $perCurrency(
                $sales()
                    ->selectRaw('payment_status, currency, COUNT(*) as count, COALESCE(SUM(total), 0) as total, COALESCE(SUM(amount_paid), 0) as amount_paid, COALESCE(SUM(CASE WHEN total > amount_paid AND payment_status <> ? THEN total - amount_paid ELSE 0 END), 0) as balance_due', [PaymentStatus::UNTRACKED->value])
                    ->groupBy('payment_status', 'currency')
                    ->get(),
                'payment_status',
                ['total', 'amount_paid', 'balance_due'],
                ['count'],
            )->keyBy('payment_status');

            $byPaymentStatus = collect(PaymentStatus::cases())
                ->filter(fn (PaymentStatus $status) => $rows->has($status->value))
                ->map(fn (PaymentStatus $status) => $rows[$status->value])
                ->values();

            $summary['outstanding_by_currency'] = CurrencyTotals::list(
                $byPaymentStatus->flatMap(fn (array $row) => $row['by_currency'])
                    ->groupBy('currency')
                    ->map(fn ($split) => $split->sum('balance_due')),
                $currency,
            );
            $summary['total_outstanding'] = $summary['outstanding_by_currency'][0]['amount'];
        }

        // Sales by status
        $byStatus = $perCurrency(
            $orders()->selectRaw('status, currency, COUNT(*) as count, COALESCE(SUM(total), 0) as revenue')->groupBy('status', 'currency')->get(),
            'status',
            ['revenue'],
            ['count'],
        );

        // Top selling products (by revenue, top 10), per product and currency:
        // the organization's currency first, then each other currency.
        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $organizationId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.order_date', [$fromTimestamp, $toTimestamp])
            ->selectRaw('
                order_items.product_id,
                order_items.product_name,
                order_items.sku,
                orders.currency,
                SUM(order_items.quantity) as quantity_sold,
                SUM(order_items.total) as revenue
            ')
            ->groupBy('order_items.product_id', 'order_items.product_name', 'order_items.sku', 'orders.currency')
            ->orderByRaw('CASE WHEN UPPER(orders.currency) = ? THEN 0 ELSE 1 END', [$currency])
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'currency' => filled($row->currency) ? strtoupper(trim((string) $row->currency)) : $currency,
                'quantity_sold' => (int) $row->quantity_sold,
                'revenue' => (float) $row->revenue,
            ])
            ->values();

        // Daily sales trend
        $dailySales = $perCurrency(
            $sales()->selectRaw('DATE(order_date) as date, currency, COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')->groupBy('date', 'currency')->orderBy('date')->get(),
            'date',
            ['revenue'],
            ['orders'],
        )->sortBy('date')->values();

        // Period-over-period: the same headline figures for the equal-length
        // period immediately before, with the change in percent (null when
        // the previous period had nothing to compare against).
        $previousPeriod = $period->previous();
        $current = $this->analytics->salesSummary($organizationId, $period, $currency);
        $previous = $this->analytics->salesSummary($organizationId, $previousPeriod, $currency);
        $delta = [];
        foreach ($current as $key => $value) {
            $delta[$key] = InventoryAnalyticsService::deltaPct((float) $value, (float) $previous[$key]);
        }
        $comparison = [
            'previousPeriod' => $previousPeriod->toFilters(),
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
        ];

        if ($format = ReportExporter::requestedFormat($request)) {
            $notes = ['Period: '.$period->label().'.'];

            // One line per currency present, never a sum across currencies.
            $split = fn ($rows, string $key, string $count, string $money) => $rows->flatMap(
                fn (array $r) => collect($r['by_currency'])
                    ->filter(fn (array $c) => $c[$count] > 0)
                    ->map(fn (array $c) => [$r[$key] instanceof \BackedEnum ? $r[$key]->value : (string) $r[$key], $c['currency'], $c[$count], $c[$money]])
            );

            return match ($request->query('group')) {
                'status' => $this->exporter->download(
                    $format,
                    'Sales by Status',
                    ['Status', 'Currency', 'Orders', 'Revenue'],
                    $split($byStatus, 'status', 'count', 'revenue'),
                    $notes
                ),
                'products' => $this->exporter->download(
                    $format,
                    'Top Selling Products',
                    ['Product', 'SKU', 'Currency', 'Units sold', 'Revenue'],
                    $topProducts->map(fn (array $r) => [$r['product_name'], $r['sku'], $r['currency'], $r['quantity_sold'], $r['revenue']]),
                    $notes
                ),
                default => $this->exporter->download(
                    $format,
                    'Sales Analysis',
                    ['Date', 'Currency', 'Orders', 'Revenue'],
                    $split($dailySales, 'date', 'orders', 'revenue'),
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
    public function receivables(Request $request, ReceivablesAgingService $aging): Response|HttpResponse
    {
        // One currency at a time (the organization's unless ?currency= names
        // another); balances in different currencies are never added up.
        $requested = $request->query('currency');
        $requested = is_string($requested) && preg_match('/^[A-Za-z]{3}$/', $requested) ? $requested : null;
        $report = $aging->build($request->user()->organization_id, currency: $requested);
        $currency = $report['currency'];

        if ($format = ReportExporter::requestedFormat($request)) {
            $notes = [
                'As of '.$report['summary']['as_of'].'. Amounts in '.$currency.' only; other currencies are exported separately (?currency=).',
                'Outstanding per currency: '.implode(', ', array_map(
                    fn (array $c) => $c['currency'].' '.$c['total_outstanding'],
                    $report['currencies'],
                )).'.',
                'Age is counted in days from the order date.',
            ];
            $buckets = array_keys(ReceivablesAgingService::BUCKETS);

            if ($request->query('group') === 'orders') {
                return $this->exporter->download(
                    $format,
                    'Receivables by Order',
                    ['Order', 'Customer', 'Currency', 'Order date', 'Status', 'Payment status', 'Total', 'Paid', 'Balance due', 'Age (days)', 'Bucket'],
                    array_map(fn (array $o) => [
                        $o['order_number'], $o['customer'], $currency, $o['order_date'], $o['status'], $o['payment_status'],
                        (float) $o['total'], (float) $o['amount_paid'], (float) $o['balance_due'], (int) $o['age_days'],
                        self::RECEIVABLES_BUCKET_LABELS[$o['bucket']] ?? $o['bucket'],
                    ], $report['orders']),
                    $report['summary']['orders_truncated']
                        ? array_merge($notes, ['Only the '.ReceivablesAgingService::ORDER_LIST_LIMIT.' oldest orders are listed.'])
                        : $notes,
                );
            }

            return $this->exporter->download(
                $format,
                'Receivables Aging',
                array_merge(['Customer', 'Currency', 'Orders'], array_map(fn (string $b) => self::RECEIVABLES_BUCKET_LABELS[$b], $buckets), ['Total']),
                array_map(fn (array $c) => array_merge(
                    [$c['customer'], $currency, (int) $c['orders']],
                    array_map(fn (string $b) => (float) $c[$b], $buckets),
                    [(float) $c['total']],
                ), $report['customers']),
                $notes,
            );
        }

        return Inertia::render('Reports/Receivables', $report);
    }

    /**
     * Column labels for the receivables aging buckets in exports.
     */
    private const RECEIVABLES_BUCKET_LABELS = [
        'current' => 'Current',
        '1_30' => '1-30 days',
        '31_60' => '31-60 days',
        '61_90' => '61-90 days',
        'over_90' => 'Over 90 days',
    ];

    /**
     * Low Stock Report.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function lowStock(Request $request): Response|HttpResponse
    {
        $organizationId = $request->user()->organization_id;

        $reorder = app(ReorderService::class);

        $base = Product::forOrganization($organizationId)
            ->where('is_active', true)
            // Low in total, or low in a warehouse with its own minimum.
            ->lowStock();

        // Headline figures cover all matching products, without hydrating
        // the entire catalogue or querying warehouse balances for each row.
        $summaryBase = (clone $base)
            ->select(['products.min_stock', 'products.max_stock', 'products.reorder_point', 'products.purchase_price', 'products.price'])
            ->withEffectiveStock();
        $totals = DB::query()->fromSub($summaryBase->toBase(), 'low_stock')
            ->selectRaw('COUNT(*) as total_low_stock,
                COALESCE(SUM(CASE WHEN effective_stock <= 0 THEN 1 ELSE 0 END), 0) as out_of_stock,
                COALESCE(SUM(CASE WHEN effective_stock > 0 THEN 1 ELSE 0 END), 0) as low_stock,
                COALESCE(SUM(CASE WHEN COALESCE(max_stock, reorder_point, min_stock, 0) > effective_stock
                    THEN (COALESCE(max_stock, reorder_point, min_stock, 0) - effective_stock) * COALESCE(purchase_price, price, 0)
                    ELSE 0 END), 0) as total_reorder_cost')
            ->first();
        $summary = [
            'total_low_stock' => (int) $totals->total_low_stock,
            'out_of_stock' => (int) $totals->out_of_stock,
            'low_stock' => (int) $totals->low_stock,
            'total_reorder_cost' => (float) $totals->total_reorder_cost,
        ];

        $products = (clone $base)
            ->with(array_merge(['category', 'location'], ReorderService::primarySupplierEagerLoad()))
            ->withEffectiveStock()
            ->orderBy('effective_stock', 'asc')
            ->orderBy('products.id')
            ->limit($this->analytics->maxRows())
            ->get();
        $warehouseOnHand = app(WarehouseStockLevelService::class)->onHandByProducts(
            $products->filter(fn (Product $product) => $product->warehouseReorderPoints->isNotEmpty())->modelKeys()
        );
        $products = $products->map(function ($product) use ($reorder, $warehouseOnHand) {
            // A product sold by variant counts its active variants.
            $stock = $product->total_stock;

            $primarySupplier = $reorder->primarySupplier($product);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'location' => $product->location?->name,
                'current_stock' => $stock,
                'min_stock' => $product->min_stock,
                'max_stock' => $product->max_stock,
                'deficit' => max(0, $product->min_stock - $stock),
                'status' => $stock <= 0 ? 'out_of_stock' : 'low_stock',
                'price' => $product->price,
                'supplier' => $primarySupplier?->name,
                'supplier_id' => $primarySupplier?->id,
                'suggested_quantity' => $reorder->suggestedQuantity($product, $primarySupplier, $warehouseOnHand[$product->id] ?? []),
                // Reorder up to max_stock; fall back to reorder_point /
                // min_stock when it's null, and never let a null or
                // already-satisfied target produce a negative cost.
                'reorder_cost' => max(0, ($product->max_stock ?? $product->reorder_point ?? $product->min_stock ?? 0) - $stock)
                    * ($product->purchase_price ?? $product->price),
            ];
        });

        if ($format = ReportExporter::requestedFormat($request)) {
            return $this->exporter->download(
                $format,
                'Low Stock',
                ['Product', 'SKU', 'Category', 'Location', 'Current stock', 'Min stock', 'Max stock', 'Deficit', 'Status', 'Supplier', 'Suggested quantity', 'Reorder cost'],
                $products->map(fn (array $r) => [
                    $r['name'], $r['sku'], $r['category'], $r['location'], (int) $r['current_stock'], (int) $r['min_stock'],
                    $r['max_stock'] === null ? null : (int) $r['max_stock'], (int) $r['deficit'], $r['status'],
                    $r['supplier'], $r['suggested_quantity'] === null ? null : (int) $r['suggested_quantity'], (float) $r['reorder_cost'],
                ])
            );
        }

        return Inertia::render('Reports/LowStock', [
            'products' => $products,
            'summary' => $summary,
            'truncated' => $summary['total_low_stock'] > $products->count(),
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

        $currency = Organization::currencyFor($organizationId);

        // One row per category and product currency. Stock value is never
        // added across currencies: each category's total_value (and its
        // average per product) is in the organization's currency, with every
        // currency under `values`; the export lists the rows as they are.
        $currencyRows = DB::table('products')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->groupBy('products.category_id', 'product_categories.name', 'products.currency')
            ->selectRaw('
                products.category_id as category_id,
                product_categories.name as category_name,
                products.currency as currency,
                COUNT(*) as product_count,
                COALESCE(SUM('.Product::effectiveStockSql().'), 0) as total_stock,
                COALESCE(SUM('.Product::stockValueSql('price').'), 0) as total_value,
                SUM(CASE WHEN '.Product::effectiveStockSql().' <= products.min_stock THEN 1 ELSE 0 END) as low_stock_items
            ')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category_name' => $row->category_name ?? 'Uncategorized',
                'currency' => filled($row->currency) ? strtoupper(trim((string) $row->currency)) : $currency,
                'product_count' => (int) $row->product_count,
                'total_stock' => (int) $row->total_stock,
                'total_value' => round((float) $row->total_value, 2),
                'low_stock_items' => (int) $row->low_stock_items,
            ]);

        if ($format = ReportExporter::requestedFormat($request)) {
            return $this->exporter->download(
                $format,
                'Category Performance',
                ['Category', 'Products', 'Total stock', 'Currency', 'Total value', 'Low stock items'],
                $currencyRows
                    ->sortBy([['category_name', 'asc'], ['currency', 'asc']])
                    ->map(fn (array $r) => [
                        $r['category_name'], $r['product_count'], $r['total_stock'], $r['currency'], $r['total_value'], $r['low_stock_items'],
                    ])
                    ->values()
            );
        }

        $categoryStats = $currencyRows
            ->groupBy(fn (array $r) => $r['category_id'] ?? 'none')
            ->map(function ($rows) use ($currency) {
                $values = CurrencyTotals::list($rows->pluck('total_value', 'currency'), $currency);
                $baseProducts = $rows->where('currency', $currency)->sum('product_count');

                return [
                    'category_id' => $rows->first()['category_id'],
                    'category_name' => $rows->first()['category_name'],
                    'product_count' => $rows->sum('product_count'),
                    'total_stock' => $rows->sum('total_stock'),
                    'total_value' => $values[0]['amount'],
                    // Per product in the organization's currency; null when
                    // the category has none.
                    'average_value' => $baseProducts > 0 ? round($values[0]['amount'] / $baseProducts, 2) : null,
                    'values' => $values,
                    'low_stock_items' => $rows->sum('low_stock_items'),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['total_value'], $a['category_name']] <=> [$a['total_value'], $b['category_name']])
            ->take($this->analytics->maxRows())
            ->values();

        $values = CurrencyTotals::list(
            $currencyRows->groupBy('currency')->map(fn ($rows) => $rows->sum('total_value')),
            $currency,
        );

        return Inertia::render('Reports/CategoryPerformance', [
            'categories' => $categoryStats,
            'summary' => [
                'total_categories' => $categoryStats->count(),
                'total_products' => $categoryStats->sum('product_count'),
                'currency' => $currency,
                'total_value' => $values[0]['amount'],
                'values_by_currency' => $values,
            ],
        ]);
    }
}
