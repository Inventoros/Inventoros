<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Support\CurrencyTotals;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates behind the analytics reports: dead stock, inventory turnover,
 * profit margin, sales by location, valuation by location and ABC analysis.
 *
 * Every figure is computed with aggregate SQL (GROUP BY / SUM / MAX over
 * joined subqueries), never by hydrating rows and looping in PHP. Row lists
 * are capped at reports.max_rows; headline totals are computed over the full
 * set so a capped list never under-reports them. The SQL uses only CASE,
 * COALESCE, MAX, SUM and COUNT, with dates compared as bound parameters, so
 * it runs unchanged on SQLite, MySQL and PostgreSQL.
 *
 * "Sales" throughout means order lines on non-cancelled, non-deleted orders
 * dated inside the period.
 */
class InventoryAnalyticsService
{
    public const DEAD_STOCK_DAYS = [30, 60, 90, 180];

    public const DEAD_STOCK_BASES = ['both', 'sales', 'movement'];

    /** Cumulative revenue share (percent) at which class A and class B end. */
    public const ABC_A_THRESHOLD = 80.0;

    public const ABC_B_THRESHOLD = 95.0;

    /**
     * Cost of goods sold uses order_items.unit_cost, the unit cost recorded
     * when the line was sold (see OrderItem::costAtSale). Lines that predate
     * that column were backfilled from the then-current cost and carry
     * unit_cost_backfilled_at; the reports count those units separately so
     * the pages can flag them as estimates. Lines with no recorded cost count
     * as zero cost and are flagged as missing.
     */
    public const COST_BASIS = 'cost_at_sale';

    /**
     * Per-line cost aggregates shared by the margin and turnover queries.
     */
    private const LINE_COST_AGGREGATES = '
        COALESCE(SUM(order_items.quantity * COALESCE(order_items.unit_cost, 0)), 0) as cogs,
        COALESCE(SUM(CASE WHEN order_items.unit_cost IS NULL THEN order_items.quantity ELSE 0 END), 0) as units_without_cost,
        COALESCE(SUM(CASE WHEN order_items.unit_cost_backfilled_at IS NOT NULL THEN order_items.quantity ELSE 0 END), 0) as units_estimated_cost
    ';

    public function maxRows(): int
    {
        return max(1, (int) config('reports.max_rows', 10000));
    }

    // ----------------------------------------------------------------------
    // Dead stock / slow-moving
    // ----------------------------------------------------------------------

    /**
     * Active products with stock on hand and no sale and/or no outbound
     * movement (negative stock adjustment) in the last $days days. Products
     * created inside the window are excluded: they have not had $days days to
     * sell yet.
     *
     * @param  string  $basis  'both' (neither a sale nor an outbound movement), 'sales' or 'movement'
     * @return array{rows: array<int, array<string, mixed>>, summary: array{product_count: int, total_units: int, total_value: float}, truncated: bool}
     */
    public function deadStock(int $organizationId, int $days, string $basis, CarbonInterface $asOf): array
    {
        $base = $this->deadStockBase($organizationId, $days, $basis, $asOf);
        $summary = $this->deadStockSummaryFrom($base, $organizationId);
        $baseCurrency = $summary['currency'];

        $rows = (clone $base)
            ->orderByDesc('tied_up_value')
            ->orderBy('products.id')
            ->limit($this->maxRows())
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'category' => $row->category,
                'location' => $row->location,
                'stock' => (int) $row->stock,
                'unit_cost' => $row->purchase_price === null ? null : (float) $row->purchase_price,
                'cost_missing' => $row->purchase_price === null,
                'tied_up_value' => round((float) $row->tied_up_value, 2),
                // Unit cost and value are in the product's currency.
                'currency' => filled($row->currency) ? strtoupper(trim((string) $row->currency)) : $baseCurrency,
                'last_sale_at' => $row->last_sale_at,
                'last_outbound_at' => $row->last_outbound_at,
            ])
            ->all();

        return [
            'rows' => $rows,
            'summary' => $summary,
            'truncated' => $summary['product_count'] > count($rows),
        ];
    }

    /**
     * Headline dead-stock figures only (one aggregate query), for the
     * dashboard tile.
     *
     * @return array{product_count: int, total_units: int, currency: string, total_value: float, values_by_currency: array<int, array{currency: string, amount: float}>}
     */
    public function deadStockSummary(int $organizationId, int $days, CarbonInterface $asOf): array
    {
        return $this->deadStockSummaryFrom($this->deadStockBase($organizationId, $days, 'both', $asOf), $organizationId);
    }

    private function deadStockBase(int $organizationId, int $days, string $basis, CarbonInterface $asOf): Builder
    {
        $asOf = CarbonImmutable::instance($asOf);
        $cutoff = $asOf->subDays($days);

        $lastSale = $this->salesLines($organizationId, null, $asOf)
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, MAX(orders.order_date) as last_sale_at');

        $lastOutbound = DB::table('stock_adjustments')
            ->where('organization_id', $organizationId)
            ->where('adjustment_quantity', '<', 0)
            ->where('created_at', '<=', $asOf)
            ->groupBy('product_id')
            ->selectRaw('product_id, MAX(created_at) as last_outbound_at');

        $query = DB::table('products')
            ->leftJoinSub($lastSale, 'ls', 'ls.product_id', '=', 'products.id')
            ->leftJoinSub($lastOutbound, 'lo', 'lo.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('product_locations', 'product_locations.id', '=', 'products.location_id')
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            // A product sold by variant counts its active variants' stock.
            ->whereRaw(Product::effectiveStockSql().' > 0')
            ->where('products.created_at', '<=', $cutoff)
            ->selectRaw('
                products.id,
                products.name,
                products.sku,
                product_categories.name as category,
                product_locations.name as location,
                '.Product::effectiveStockSql().' as stock,
                products.purchase_price,
                products.currency,
                '.Product::stockValueSql('purchase_price').' as tied_up_value,
                ls.last_sale_at,
                lo.last_outbound_at
            ');

        if ($basis === 'both' || $basis === 'sales') {
            $query->where(fn (Builder $q) => $q->whereNull('ls.last_sale_at')->orWhere('ls.last_sale_at', '<', $cutoff));
        }

        if ($basis === 'both' || $basis === 'movement') {
            $query->where(fn (Builder $q) => $q->whereNull('lo.last_outbound_at')->orWhere('lo.last_outbound_at', '<', $cutoff));
        }

        return $query;
    }

    /**
     * Counts across every product, value per product currency: total_value
     * is the organization's currency only and values_by_currency lists each
     * currency's own total (that currency first), so amounts in different
     * currencies are never added together.
     *
     * @return array{product_count: int, total_units: int, currency: string, total_value: float, values_by_currency: array<int, array{currency: string, amount: float}>}
     */
    private function deadStockSummaryFrom(Builder $base, int $organizationId): array
    {
        $groups = DB::query()
            ->fromSub(clone $base, 'dead')
            ->groupBy('currency')
            ->selectRaw('currency, COUNT(*) as product_count, COALESCE(SUM(stock), 0) as total_units, COALESCE(SUM(tied_up_value), 0) as total_value')
            ->get();

        $currency = Organization::currencyFor($organizationId);
        $values = CurrencyTotals::list($groups->pluck('total_value', 'currency'), $currency);

        return [
            'product_count' => (int) $groups->sum('product_count'),
            'total_units' => (int) $groups->sum('total_units'),
            'currency' => $currency,
            'total_value' => $values[0]['amount'],
            'values_by_currency' => $values,
        ];
    }

    // ----------------------------------------------------------------------
    // Inventory turnover
    // ----------------------------------------------------------------------

    /**
     * Turnover = COGS / average inventory value; days of inventory =
     * period days / turnover.
     *
     * Average inventory approximation: the product's on-hand units at the
     * END of the period are reconstructed as current stock minus the net of
     * every stock adjustment recorded after the period; units at the START
     * are that closing figure minus the net of adjustments inside the period.
     * Average units = (opening + closing) / 2, each clamped at zero, valued at
     * the current purchase price (the value of stock on hand). Stock changes
     * that bypassed the adjustment ledger are not reflected, and
     * variant-level adjustments are ignored because they move variant stock,
     * not the product's own.
     *
     * COGS = the sum of each sold line's quantity x its unit cost recorded at
     * sale (see COST_BASIS).
     *
     * @return array{products: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>, summary: array<string, mixed>, truncated: bool}
     */
    public function inventoryTurnover(int $organizationId, ReportPeriod $period): array
    {
        $sold = $this->salesLines($organizationId, $period->from, $period->to)
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, SUM(order_items.quantity) as units_sold, '.self::LINE_COST_AGGREGATES);

        $adjustments = DB::table('stock_adjustments')
            ->where('organization_id', $organizationId)
            ->whereNull('product_variant_id')
            ->where('created_at', '>=', $period->from)
            ->groupBy('product_id')
            ->selectRaw(
                'product_id,
                SUM(CASE WHEN created_at > ? THEN adjustment_quantity ELSE 0 END) as after_net,
                SUM(CASE WHEN created_at <= ? THEN adjustment_quantity ELSE 0 END) as within_net',
                [$period->to, $period->to]
            );

        $closing = '(products.stock - COALESCE(adj.after_net, 0))';
        $opening = "({$closing} - COALESCE(adj.within_net, 0))";
        $closingClamped = "(CASE WHEN {$closing} < 0 THEN 0 ELSE {$closing} END)";
        $openingClamped = "(CASE WHEN {$opening} < 0 THEN 0 ELSE {$opening} END)";
        $cost = 'COALESCE(products.purchase_price, 0)';

        $base = DB::table('products')
            ->leftJoinSub($sold, 'sold', 'sold.product_id', '=', 'products.id')
            ->leftJoinSub($adjustments, 'adj', 'adj.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->where(fn (Builder $q) => $q
                ->where('sold.units_sold', '>', 0)
                ->orWhere('products.stock', '>', 0)
                ->orWhereNotNull('adj.product_id'))
            ->selectRaw("
                products.id,
                products.name,
                products.sku,
                products.category_id,
                product_categories.name as category,
                COALESCE(sold.units_sold, 0) as units_sold,
                {$openingClamped} as opening_units,
                {$closingClamped} as closing_units,
                ((({$openingClamped}) + ({$closingClamped})) / 2.0) * {$cost} as average_value,
                COALESCE(sold.cogs, 0) as cogs,
                COALESCE(sold.units_without_cost, 0) as units_without_cost,
                COALESCE(sold.units_estimated_cost, 0) as units_estimated_cost
            ");

        $days = $period->days();

        $products = (clone $base)
            ->orderByDesc('cogs')
            ->orderBy('products.id')
            ->limit($this->maxRows())
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'category' => $row->category,
                'units_sold' => (int) $row->units_sold,
                'opening_units' => round((float) $row->opening_units, 2),
                'closing_units' => round((float) $row->closing_units, 2),
            ] + $this->turnoverFigures((float) $row->cogs, (float) $row->average_value, $days))
            ->all();

        $categories = DB::query()
            ->fromSub(clone $base, 't')
            ->groupBy('category_id', 'category')
            ->selectRaw('category_id, category, COUNT(*) as products, SUM(units_sold) as units_sold, SUM(cogs) as cogs, SUM(average_value) as average_value')
            ->orderByDesc('cogs')
            ->limit($this->maxRows())
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category' => $row->category,
                'products' => (int) $row->products,
                'units_sold' => (int) $row->units_sold,
            ] + $this->turnoverFigures((float) $row->cogs, (float) $row->average_value, $days))
            ->all();

        $totals = DB::query()
            ->fromSub(clone $base, 't')
            ->selectRaw('COUNT(*) as products, COALESCE(SUM(cogs), 0) as cogs, COALESCE(SUM(average_value), 0) as average_value,
                COALESCE(SUM(units_without_cost), 0) as units_without_cost, COALESCE(SUM(units_estimated_cost), 0) as units_estimated_cost')
            ->first();

        return [
            'products' => $products,
            'categories' => $categories,
            'summary' => [
                'products' => (int) $totals->products,
                'period_days' => $days,
                'units_without_cost' => (int) $totals->units_without_cost,
                'units_estimated_cost' => (int) $totals->units_estimated_cost,
            ] + $this->turnoverFigures((float) $totals->cogs, (float) $totals->average_value, $days),
            'truncated' => (int) $totals->products > count($products),
            'cost_basis' => self::COST_BASIS,
        ];
    }

    /** @return array{cogs: float, average_value: float, turnover: float|null, days_of_inventory: float|null} */
    private function turnoverFigures(float $cogs, float $averageValue, int $days): array
    {
        $ratio = $averageValue > 0 ? $cogs / $averageValue : null;

        return [
            'cogs' => round($cogs, 2),
            'average_value' => round($averageValue, 2),
            'turnover' => $ratio === null ? null : round($ratio, 2),
            'days_of_inventory' => $ratio !== null && $ratio > 0 ? round($days / $ratio, 1) : null,
        ];
    }

    // ----------------------------------------------------------------------
    // Profit margin
    // ----------------------------------------------------------------------

    /**
     * Revenue (order line subtotals, i.e. before tax), COGS at the unit cost
     * recorded at sale (see COST_BASIS), gross margin and margin % per product
     * and per category. Lines with no recorded cost count as zero cost and are
     * flagged; backfilled (estimated) costs are counted and flagged too.
     *
     * @return array{products: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>, summary: array<string, mixed>, cost_basis: string, truncated: bool}
     */
    public function profitMargin(int $organizationId, ReportPeriod $period): array
    {
        $aggregates = '
            SUM(order_items.quantity) as units,
            COALESCE(SUM(order_items.subtotal), 0) as revenue,
        '.self::LINE_COST_AGGREGATES;

        $lines = fn () => $this->costedSalesLines($organizationId, $period);

        $products = $lines()
            ->groupBy('order_items.product_id')
            ->selectRaw("
                order_items.product_id as product_id,
                COALESCE(MAX(products.name), MAX(order_items.product_name)) as name,
                COALESCE(MAX(products.sku), MAX(order_items.sku)) as sku,
                MAX(product_categories.name) as category,
                {$aggregates}
            ")
            ->orderByDesc('revenue')
            ->limit($this->maxRows())
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'name' => $row->name,
                'sku' => $row->sku,
                'category' => $row->category,
            ] + $this->marginFigures($row))
            ->all();

        $categories = $lines()
            ->groupBy('products.category_id', 'product_categories.name')
            ->selectRaw("products.category_id as category_id, product_categories.name as category, {$aggregates}")
            ->orderByDesc('revenue')
            ->limit($this->maxRows())
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category' => $row->category,
            ] + $this->marginFigures($row))
            ->all();

        $totals = $lines()
            ->selectRaw("COUNT(DISTINCT order_items.product_id) as products, {$aggregates}")
            ->first();

        return [
            'products' => $products,
            'categories' => $categories,
            'summary' => $this->marginFigures($totals),
            'cost_basis' => self::COST_BASIS,
            'truncated' => (int) $totals->products > count($products),
        ];
    }

    /** @return array{units: int, revenue: float, cogs: float, margin: float, margin_pct: float|null, units_without_cost: int, cost_missing: bool, units_estimated_cost: int, cost_estimated: bool} */
    private function marginFigures(object $row): array
    {
        $revenue = (float) $row->revenue;
        $cogs = (float) $row->cogs;
        $margin = $revenue - $cogs;
        $missing = (int) $row->units_without_cost;

        return [
            'units' => (int) $row->units,
            'revenue' => round($revenue, 2),
            'cogs' => round($cogs, 2),
            'margin' => round($margin, 2),
            'margin_pct' => $revenue > 0 ? round($margin / $revenue * 100, 2) : null,
            'units_without_cost' => $missing,
            'cost_missing' => $missing > 0,
            'units_estimated_cost' => (int) $row->units_estimated_cost,
            'cost_estimated' => (int) $row->units_estimated_cost > 0,
        ];
    }

    private function costedSalesLines(int $organizationId, ReportPeriod $period): Builder
    {
        return $this->salesLines($organizationId, $period->from, $period->to)
            ->leftJoin('products', function (JoinClause $join) use ($organizationId) {
                $join->on('products.id', '=', 'order_items.product_id')
                    ->where('products.organization_id', '=', $organizationId);
            })
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id');
    }

    // ----------------------------------------------------------------------
    // Sales by warehouse / location
    // ----------------------------------------------------------------------

    /**
     * Sales (order totals, as on the Sales Analysis report) by the order's
     * warehouse, and units + line totals by the sold product's assigned
     * location, each with the previous equal-length period and its delta %.
     *
     * @param  array<int, int>|null  $warehouseIds  The viewer's accessible warehouses
     *                                              (WarehouseAccessService::accessibleWarehouseIds); null = unrestricted.
     *                                              A restricted viewer sees only those warehouses and their locations,
     *                                              never the "no warehouse" groups.
     * @return array{byWarehouse: array<int, array<string, mixed>>, byLocation: array<int, array<string, mixed>>, previousPeriod: array{date_from: string, date_to: string}}
     */
    public function salesByLocation(int $organizationId, ReportPeriod $period, ?array $warehouseIds = null): array
    {
        $previous = $period->previous();

        $warehouses = fn (ReportPeriod $p) => $this->salesOrders($organizationId, $p->from, $p->to)
            ->leftJoin('warehouses', function (JoinClause $join) use ($organizationId) {
                $join->on('warehouses.id', '=', 'orders.warehouse_id')
                    ->where('warehouses.organization_id', '=', $organizationId);
            })
            ->when($warehouseIds !== null, fn (Builder $q) => $q->whereIn('orders.warehouse_id', $warehouseIds))
            ->groupBy('orders.warehouse_id', 'warehouses.name')
            ->selectRaw('orders.warehouse_id as id, warehouses.name as name, COUNT(*) as orders, COALESCE(SUM(orders.total), 0) as revenue')
            ->orderByDesc('revenue')
            ->limit($this->maxRows())
            ->get();

        $locations = fn (ReportPeriod $p) => $this->salesLines($organizationId, $p->from, $p->to)
            ->leftJoin('products', function (JoinClause $join) use ($organizationId) {
                $join->on('products.id', '=', 'order_items.product_id')
                    ->where('products.organization_id', '=', $organizationId);
            })
            ->leftJoin('product_locations', function (JoinClause $join) use ($organizationId) {
                $join->on('product_locations.id', '=', 'products.location_id')
                    ->where('product_locations.organization_id', '=', $organizationId);
            })
            ->leftJoin('warehouses', function (JoinClause $join) use ($organizationId) {
                $join->on('warehouses.id', '=', 'product_locations.warehouse_id')
                    ->where('warehouses.organization_id', '=', $organizationId);
            })
            ->when($warehouseIds !== null, fn (Builder $q) => $q->whereIn('product_locations.warehouse_id', $warehouseIds))
            ->groupBy('product_locations.id', 'product_locations.name', 'warehouses.name')
            ->selectRaw('product_locations.id as id, product_locations.name as name, warehouses.name as warehouse, COUNT(DISTINCT orders.id) as orders, COALESCE(SUM(order_items.quantity), 0) as units, COALESCE(SUM(order_items.total), 0) as revenue')
            ->orderByDesc('revenue')
            ->limit($this->maxRows())
            ->get();

        return [
            'byWarehouse' => $this->mergeWithPrevious($warehouses($period), $warehouses($previous), 'warehouse_id', false),
            'byLocation' => $this->mergeWithPrevious($locations($period), $locations($previous), 'location_id', true),
            'previousPeriod' => $previous->toFilters(),
        ];
    }

    /**
     * Headline sales figures for a period, with the same basis as the Sales
     * Analysis summary (all orders dated in the period).
     *
     * @return array{total_orders: int, total_revenue: float, total_items_sold: int, average_order_value: float}
     */
    public function salesSummary(int $organizationId, ReportPeriod $period): array
    {
        $orders = DB::table('orders')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereBetween('order_date', [$period->from, $period->to])
            ->selectRaw('COUNT(*) as total_orders, COALESCE(SUM(total), 0) as total_revenue')
            ->first();

        $items = (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $organizationId)
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.order_date', [$period->from, $period->to])
            ->sum('order_items.quantity');

        $count = (int) $orders->total_orders;
        $revenue = (float) $orders->total_revenue;

        return [
            'total_orders' => $count,
            'total_revenue' => round($revenue, 2),
            'total_items_sold' => $items,
            'average_order_value' => $count > 0 ? round($revenue / $count, 2) : 0.0,
        ];
    }

    public static function deltaPct(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    /**
     * @param  Collection<int, object>  $current
     * @param  Collection<int, object>  $previous
     * @return array<int, array<string, mixed>>
     */
    private function mergeWithPrevious($current, $previous, string $idKey, bool $withUnits): array
    {
        $key = fn ($row) => $row->id === null ? 'none' : (string) $row->id;
        $previousByKey = $previous->keyBy($key);

        $rows = [];
        foreach ($current as $row) {
            $rows[$key($row)] = $row;
        }
        // Keep groups that sold only in the previous period, so a location
        // whose sales dropped to zero shows as -100% rather than vanishing.
        foreach ($previous as $row) {
            if (! isset($rows[$key($row)])) {
                $rows[$key($row)] = (object) ['id' => $row->id, 'name' => $row->name, 'warehouse' => $row->warehouse ?? null, 'orders' => 0, 'units' => 0, 'revenue' => 0];
            }
        }

        return array_values(array_map(function ($row) use ($previousByKey, $key, $idKey, $withUnits) {
            $prev = $previousByKey->get($key($row));
            $revenue = round((float) $row->revenue, 2);
            $prevRevenue = $prev === null ? 0.0 : round((float) $prev->revenue, 2);

            $out = [
                $idKey => $row->id === null ? null : (int) $row->id,
                'name' => $row->name,
                'orders' => (int) $row->orders,
                'revenue' => $revenue,
                'previous_orders' => $prev === null ? 0 : (int) $prev->orders,
                'previous_revenue' => $prevRevenue,
                'delta_pct' => self::deltaPct($revenue, $prevRevenue),
            ];

            if ($withUnits) {
                $out['warehouse'] = $row->warehouse ?? null;
                $out['units'] = (int) $row->units;
                $out['previous_units'] = $prev === null ? 0 : (int) $prev->units;
            }

            return $out;
        }, $rows));
    }

    // ----------------------------------------------------------------------
    // Valuation by location
    // ----------------------------------------------------------------------

    /**
     * On-hand units and their cost / retail value per stock location, from the
     * per-location stock table. Units a product holds beyond its per-location
     * allocations are reported as one unallocated row (location_id null).
     *
     * @param  array<int, int>|null  $warehouseIds  The viewer's accessible warehouses; null = unrestricted.
     *                                              A restricted viewer sees only locations in those
     *                                              warehouses and no unallocated row.
     * @return array<int, array<string, mixed>>
     */
    public function valuationByLocation(int $organizationId, ?array $warehouseIds = null): array
    {
        $currency = Organization::currencyFor($organizationId);

        $rows = DB::table('product_location_stocks')
            ->join('products', function (JoinClause $join) use ($organizationId) {
                $join->on('products.id', '=', 'product_location_stocks.product_id')
                    ->where('products.organization_id', '=', $organizationId);
            })
            ->join('product_locations', function (JoinClause $join) use ($organizationId) {
                $join->on('product_locations.id', '=', 'product_location_stocks.location_id')
                    ->where('product_locations.organization_id', '=', $organizationId);
            })
            ->leftJoin('warehouses', function (JoinClause $join) use ($organizationId) {
                $join->on('warehouses.id', '=', 'product_locations.warehouse_id')
                    ->where('warehouses.organization_id', '=', $organizationId);
            })
            ->where('product_location_stocks.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->when($warehouseIds !== null, fn (Builder $q) => $q->whereIn('product_locations.warehouse_id', $warehouseIds))
            ->groupBy('product_locations.id', 'product_locations.name', 'warehouses.name', 'products.currency')
            ->selectRaw('
                product_locations.id as location_id,
                product_locations.name as location,
                warehouses.name as warehouse,
                products.currency as currency,
                COUNT(DISTINCT products.id) as products,
                COALESCE(SUM(product_location_stocks.quantity), 0) as quantity,
                COALESCE(SUM(product_location_stocks.quantity * COALESCE(products.purchase_price, 0)), 0) as cost_value,
                COALESCE(SUM(product_location_stocks.quantity * products.price), 0) as retail_value
            ')
            ->get()
            ->groupBy('location_id')
            ->map(fn (Collection $group) => $this->locationValuationRow(
                (int) $group->first()->location_id,
                $group->first()->location,
                $group->first()->warehouse,
                $group,
                $currency,
            ))
            ->sort(fn (array $a, array $b) => [$b['cost_value'], $a['location_id']] <=> [$a['cost_value'], $b['location_id']])
            ->take($this->maxRows())
            ->values()
            ->all();

        if ($warehouseIds !== null) {
            return $rows;
        }

        $allocated = DB::table('product_location_stocks')
            ->where('organization_id', $organizationId)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity');

        $remainder = '(products.stock - COALESCE(alloc.quantity, 0))';
        $positive = "(CASE WHEN {$remainder} > 0 THEN {$remainder} ELSE 0 END)";

        $unallocated = DB::table('products')
            ->leftJoinSub($allocated, 'alloc', 'alloc.product_id', '=', 'products.id')
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->groupBy('products.currency')
            ->selectRaw("
                products.currency as currency,
                COALESCE(SUM(CASE WHEN {$remainder} > 0 THEN 1 ELSE 0 END), 0) as products,
                COALESCE(SUM({$positive}), 0) as quantity,
                COALESCE(SUM({$positive} * COALESCE(products.purchase_price, 0)), 0) as cost_value,
                COALESCE(SUM({$positive} * products.price), 0) as retail_value
            ")
            ->get();

        if ((int) $unallocated->sum('quantity') > 0) {
            $rows[] = $this->locationValuationRow(null, null, null, $unallocated, $currency);
        }

        return $rows;
    }

    /**
     * One valuation-by-location row from its per-currency groups: counts
     * across every product, cost and retail value in the organization's
     * currency, and `values` listing each currency's own (that one first).
     *
     * @param  Collection<int, object>  $groups
     * @return array<string, mixed>
     */
    private function locationValuationRow(?int $locationId, ?string $location, ?string $warehouse, Collection $groups, string $currency): array
    {
        $values = CurrencyTotals::breakdown($groups, $currency, ['cost_value', 'retail_value'], ['products', 'quantity']);

        return [
            'location_id' => $locationId,
            'location' => $location,
            'warehouse' => $warehouse,
            'products' => (int) $groups->sum('products'),
            'quantity' => (int) $groups->sum('quantity'),
            'currency' => $currency,
            'cost_value' => $values[0]['cost_value'],
            'retail_value' => $values[0]['retail_value'],
            'values' => $values,
        ];
    }

    // ----------------------------------------------------------------------
    // ABC analysis
    // ----------------------------------------------------------------------

    /**
     * Rank products by revenue (line subtotals, before tax) in the period and
     * classify them by cumulative share: A while the share BEFORE the product
     * is under 80%, B while it is under 95%, C after that. The cumulative
     * pass runs in PHP over the already-aggregated, capped product list; the
     * grand total comes from SQL over every line, so shares stay correct
     * even when the list is capped (anything past the cap is class C).
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, array{count: int, revenue: float, share_pct: float}>, total_revenue: float, truncated: bool}
     */
    public function abcAnalysis(int $organizationId, ReportPeriod $period): array
    {
        $totals = $this->salesLines($organizationId, $period->from, $period->to)
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as revenue, COUNT(DISTINCT order_items.product_id) as products')
            ->first();
        $total = (float) $totals->revenue;

        $ranked = $this->salesLines($organizationId, $period->from, $period->to)
            ->leftJoin('products', function (JoinClause $join) use ($organizationId) {
                $join->on('products.id', '=', 'order_items.product_id')
                    ->where('products.organization_id', '=', $organizationId);
            })
            ->groupBy('order_items.product_id')
            ->havingRaw('SUM(order_items.subtotal) > 0')
            ->selectRaw('
                order_items.product_id as product_id,
                COALESCE(MAX(products.name), MAX(order_items.product_name)) as name,
                COALESCE(MAX(products.sku), MAX(order_items.sku)) as sku,
                SUM(order_items.quantity) as units,
                SUM(order_items.subtotal) as revenue
            ')
            ->orderByDesc('revenue')
            ->orderBy('order_items.product_id')
            ->limit($this->maxRows())
            ->get();

        $summary = [];
        foreach (['A', 'B', 'C'] as $class) {
            $summary[$class] = ['count' => 0, 'revenue' => 0.0, 'share_pct' => 0.0];
        }

        $rows = [];
        $running = 0.0;
        foreach ($ranked as $row) {
            $revenue = (float) $row->revenue;
            $before = $total > 0 ? $running / $total * 100 : 100.0;
            $class = $before < self::ABC_A_THRESHOLD ? 'A' : ($before < self::ABC_B_THRESHOLD ? 'B' : 'C');
            $running += $revenue;

            $rows[] = [
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'name' => $row->name,
                'sku' => $row->sku,
                'units' => (int) $row->units,
                'revenue' => round($revenue, 2),
                'share_pct' => $total > 0 ? round($revenue / $total * 100, 2) : 0.0,
                'cumulative_pct' => $total > 0 ? round($running / $total * 100, 2) : 0.0,
                'class' => $class,
            ];

            $summary[$class]['count']++;
            $summary[$class]['revenue'] += $revenue;
        }

        foreach ($summary as $class => $figures) {
            $summary[$class]['revenue'] = round($figures['revenue'], 2);
            $summary[$class]['share_pct'] = $total > 0 ? round($figures['revenue'] / $total * 100, 2) : 0.0;
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
            'total_revenue' => round($total, 2),
            'truncated' => (int) $totals->products > count($rows),
        ];
    }

    // ----------------------------------------------------------------------
    // Shared query bases
    // ----------------------------------------------------------------------

    /** Order lines on the organization's non-cancelled, non-deleted orders in [from, to]. */
    private function salesLines(int $organizationId, ?CarbonInterface $from, CarbonInterface $to): Builder
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $organizationId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.order_date', '<=', $to);

        if ($from !== null) {
            $query->where('orders.order_date', '>=', $from);
        }

        return $query;
    }

    private function salesOrders(int $organizationId, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return DB::table('orders')
            ->where('orders.organization_id', $organizationId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.order_date', [$from, $to]);
    }
}
