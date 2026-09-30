<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\PluginUIService;
use App\Services\ReorderService;
use App\Services\Reports\InventoryAnalyticsService;
use App\Support\CurrencyTotals;
use App\Support\SchedulerHealth;
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

        // A dashboard tile must not disclose what the page it summarises would
        // refuse to show. Every figure below is gated on the permission that
        // already guards its own screen: products and orders on their index
        // routes, and the two money aggregates on view_reports, because
        // reports.inventory-valuation and reports.sales-analysis show exactly
        // those numbers and sit behind permission:view_reports.
        //
        // Gating happens here rather than in the template so the values never
        // enter the Inertia payload. It also stops the queries running at all
        // for a user who could not be shown the answer.
        $canViewProducts = $user->hasPermission(Permission::VIEW_PRODUCTS);
        $canViewOrders = $user->hasPermission(Permission::VIEW_ORDERS);
        $canViewReports = $user->hasPermission(Permission::VIEW_REPORTS);
        $canViewActivity = $user->hasPermission(Permission::VIEW_ACTIVITY_LOG);
        // Receivables are a money aggregate (view_reports, like the others)
        // built from payments (view_payments, like reports.receivables).
        $canViewReceivables = $canViewReports && $user->hasPermission(Permission::VIEW_PAYMENTS);

        // Consolidate the five product-level aggregates (count, active stock
        // value, low-stock count) into one selectRaw round-trip and the three
        // order-level aggregates (count, pending count, month revenue) into
        // another. Categories and locations stay as separate counts because
        // they live in different tables.
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        // The product round-trip carries both the counts (view_products) and
        // the valuation (view_reports), so it runs when either is held and the
        // individual figures are picked out below. Same for orders.
        $productAgg = ($canViewProducts || $canViewReports)
            ? Product::where('organization_id', $orgId)
                ->selectRaw('
                    COUNT(*) as total_count,
                    SUM(CASE WHEN '.Product::effectiveStockSql().' <= products.min_stock THEN 1 ELSE 0 END) as low_stock_count
                ')
                ->first()
            : null;

        $orderAgg = ($canViewOrders || $canViewReports)
            ? Order::where('organization_id', $orgId)
                ->selectRaw('
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count
                ', ['pending'])
                ->first()
            : null;

        $stats = [];

        if ($canViewProducts) {
            $stats['totalProducts'] = (int) ($productAgg->total_count ?? 0);
            $stats['lowStockProducts'] = (int) ($productAgg->low_stock_count ?? 0);
            $stats['categories'] = ProductCategory::where('organization_id', $orgId)->count();
            $stats['locations'] = ProductLocation::where('organization_id', $orgId)->count();
        }

        if ($canViewOrders) {
            $stats['totalOrders'] = (int) ($orderAgg->total_count ?? 0);
            $stats['pendingOrders'] = (int) ($orderAgg->pending_count ?? 0);
        }

        // Money tiles never add up amounts in different currencies. Each
        // headline figure is the organization's currency only; the per-
        // currency breakdown (that currency first, then any other with an
        // amount) travels in stats.byCurrency for the tile to list.
        $currency = Organization::currencyFor($orgId);
        $byCurrency = [];
        $money = function (string $key, iterable $amounts) use (&$stats, &$byCurrency, $currency): void {
            $byCurrency[$key] = CurrencyTotals::list($amounts, $currency);
            $stats[$key] = $byCurrency[$key][0]['amount'];
        };

        if ($canViewReports) {
            $money('totalValue', Product::where('organization_id', $orgId)
                ->where('is_active', true)
                ->groupBy('currency')
                ->selectRaw('currency, COALESCE(SUM('.Product::stockValueSql('price').'), 0) as amount')
                ->pluck('amount', 'currency'));

            $money('revenueThisMonth', Order::where('organization_id', $orgId)
                ->where('order_date', '>=', $monthStart)
                ->where('order_date', '<=', $monthEnd)
                ->groupBy('currency')
                ->selectRaw('currency, COALESCE(SUM(total), 0) as amount')
                ->pluck('amount', 'currency'));
        }

        // Stock idle for 90 days (no sale and no outbound movement), at cost.
        // It is the dead stock report's headline, so it takes that report's
        // permissions; one aggregate query, skipped entirely otherwise.
        if ($canViewReports && $canViewProducts && $canViewOrders) {
            $deadStock = app(InventoryAnalyticsService::class)->deadStockSummary($orgId, 90, now());
            $money('deadStockValue', array_column($deadStock['values_by_currency'], 'amount', 'currency'));
            $stats['deadStockCount'] = $deadStock['product_count'];
        }

        if ($canViewReceivables) {
            // What customers still owe on live orders. Overpaid orders owe
            // nothing (they don't offset others) and cancelled orders are out,
            // as are untracked orders from before payment tracking.
            $money('outstandingReceivables', Order::where('organization_id', $orgId)
                ->where('status', '!=', 'cancelled')
                ->where('payment_status', '!=', 'untracked')
                ->whereColumn('total', '>', 'amount_paid')
                ->groupBy('currency')
                ->selectRaw('currency, SUM(total - amount_paid) as amount')
                ->pluck('amount', 'currency'));
        }

        if ($byCurrency !== []) {
            $stats['byCurrency'] = $byCurrency;
        }

        // Hook: Allow plugins to modify stats
        $stats = apply_filters('dashboard_stats_data', $stats, $user);

        // Action: Stats calculated
        do_action('dashboard_stats_calculated', $stats, $user);

        // Alias kept for plugins written against the name the guide used.
        do_action('dashboard_stats', $stats, $user);

        // Get recent products
        $recentProducts = ! $canViewProducts ? collect() : Product::where('organization_id', $user->organization_id)
            ->with(['category', 'location'])
            ->latest()
            ->limit(5)
            ->get();

        // Get low stock products
        $lowStockProducts = ! $canViewProducts ? collect() : Product::where('organization_id', $user->organization_id)
            ->whereRaw(Product::effectiveStockSql().' <= products.min_stock')
            ->withEffectiveStock()
            ->with(['category', 'location'])
            ->orderBy('effective_stock', 'asc')
            ->limit(5)
            ->get();

        // Recent orders: the ones most recently entered (created_at), so a
        // backdated or imported order just added still shows; each row
        // displays its order date.
        $recentOrders = ! $canViewOrders ? collect() : Order::where('organization_id', $user->organization_id)
            ->with('items')
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();

        // Get stock value by category. This is reports.category-performance
        // in miniature, so it takes the same permission that page does.
        $stockByCategory = ! $canViewReports ? collect() : ProductCategory::where('product_categories.organization_id', $user->organization_id)
            ->leftJoin('products', function ($join) {
                $join->on('products.category_id', '=', 'product_categories.id')
                    ->where('products.is_active', true);
            })
            ->selectRaw('product_categories.name, product_categories.id, products.currency, COALESCE(SUM('.Product::stockValueSql('price').'), 0) as value, COUNT(products.id) as count')
            ->groupBy('product_categories.id', 'product_categories.name', 'products.currency')
            ->get()
            // One card per category. Its value is in the organization's
            // currency; `values` lists every currency's own total (the
            // organization's first) rather than adding them together.
            ->groupBy('id')
            ->map(function ($rows) use ($currency) {
                $values = CurrencyTotals::list($rows->pluck('value', 'currency'), $currency);

                return [
                    'id' => $rows->first()->id,
                    'name' => $rows->first()->name,
                    'value' => $values[0]['amount'],
                    'values' => $values,
                    'count' => (int) $rows->sum('count'),
                ];
            })
            ->values();

        // Get recent activity logs
        $recentActivity = ! $canViewActivity ? collect() : ActivityLog::where('organization_id', $user->organization_id)
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
        $reorder = app(ReorderService::class);
        $reorderSuggestions = ! $canViewProducts ? collect() : Product::where('organization_id', $user->organization_id)
            ->needsReorder()
            ->withEffectiveStock()
            ->with(array_merge(['category'], ReorderService::primarySupplierEagerLoad()))
            ->orderBy('effective_stock', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($product) use ($reorder) {
                $primarySupplier = $reorder->primarySupplier($product);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => $product->total_stock,
                    'reorder_point' => $product->reorder_point,
                    'reorder_quantity' => $product->reorder_quantity,
                    'suggested_quantity' => $reorder->suggestedQuantity($product, $primarySupplier),
                    // Warehouses whose own reorder point triggered this.
                    'warehouses' => array_column($reorder->warehouseShortfalls($product), 'warehouse_name'),
                    'category' => $product->category?->name,
                    'supplier' => $primarySupplier?->name,
                    'supplier_id' => $primarySupplier?->id,
                ];
            });

        // Get stock movements (last 7 days)
        $stockMovements = ! $canViewProducts ? collect() : StockAdjustment::where('organization_id', $user->organization_id)
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
        $topProducts = ! $canViewProducts ? collect() : Product::where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->whereRaw(Product::effectiveStockSql().' > 0')
            ->selectRaw('id, name, sku, currency, price, '.Product::effectiveStockSql().' as stock, '.Product::stockValueSql('price').' as total_value')
            // Values in different currencies don't rank against each other:
            // products in the organization's currency first, then each other
            // currency's, highest value first within a currency. Each row
            // carries its currency.
            ->orderByRaw('CASE WHEN UPPER(currency) = ? THEN 0 ELSE 1 END', [$currency])
            ->orderByRaw('UPPER(currency)')
            ->orderByRaw(Product::stockValueSql('price').' DESC')
            ->limit(5)
            ->get()
            ->each(function (Product $product) use ($currency) {
                $product->currency = filled($product->currency) ? strtoupper(trim((string) $product->currency)) : $currency;
            });

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

        // Widget preferences are a display choice, not an access control: the
        // user sets them, so a preference alone can never be what keeps a
        // figure hidden. Turn off anything the permissions do not allow, so a
        // withheld card disappears rather than rendering an empty state that
        // implies the organisation has no orders.
        $widgetAllowed = [
            'stats_overview' => $canViewProducts || $canViewOrders || $canViewReports,
            'revenue_chart' => $canViewProducts || $canViewOrders || $canViewReports,
            'stock_movements' => $canViewProducts,
            'low_stock_alerts' => $canViewProducts,
            'recent_orders' => $canViewOrders,
            'recent_products' => $canViewProducts,
            'top_products' => $canViewProducts,
            'stock_by_category' => $canViewReports,
            'reorder_suggestions' => $canViewProducts,
        ];

        foreach ($widgetAllowed as $widget => $allowed) {
            $widgetPreferences[$widget] = $widgetPreferences[$widget] && $allowed;
        }

        $data = [
            'stats' => $stats,
            // The organization's currency: what the headline money figures are in.
            'currency' => $currency,
            'recentProducts' => $recentProducts,
            'lowStockProducts' => $lowStockProducts,
            'reorderSuggestions' => $reorderSuggestions,
            'recentOrders' => $recentOrders,
            'stockByCategory' => $stockByCategory,
            'recentActivity' => $recentActivity,
            'can' => [
                'viewProducts' => $canViewProducts,
                'viewOrders' => $canViewOrders,
                'viewReports' => $canViewReports,
            ],
            'stockMovements' => $stockMovements,
            // Scheduler / queue health, for the people who can fix the cron.
            'systemWarnings' => $user->is_admin ? app(SchedulerHealth::class)->warnings() : [],
            'topProducts' => $topProducts,
            'widgetPreferences' => $widgetPreferences,
            // Plugin widgets (register_dashboard_widget), gated like the figures
            // above: a widget the user may not see is absent, not empty.
            'pluginWidgets' => app(PluginUIService::class)->getVisibleDashboardWidgets($user),
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
