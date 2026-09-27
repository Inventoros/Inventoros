<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\InventoryAnalyticsService;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Analytics reports: dead stock, inventory turnover, profit margin, sales by
 * warehouse/location and ABC analysis.
 *
 * Routes sit behind view_reports plus the view permission of each data source
 * the report reads (see routes/web/reports.php), mirroring the report
 * builder's per-source rule. Every page route also serves the export: add
 * ?export=csv|xlsx|pdf (and ?group= for the alternate breakdown).
 */
class AnalyticsReportController extends Controller
{
    public function __construct(
        private readonly InventoryAnalyticsService $analytics,
        private readonly ReportExporter $exporter,
    ) {}

    public function deadStock(Request $request): Response|HttpResponse
    {
        $days = (int) $request->query('days', 90);
        if (! in_array($days, InventoryAnalyticsService::DEAD_STOCK_DAYS, true)) {
            $days = 90;
        }
        $basis = (string) $request->query('basis', 'both');
        if (! in_array($basis, InventoryAnalyticsService::DEAD_STOCK_BASES, true)) {
            $basis = 'both';
        }

        $result = $this->analytics->deadStock($request->user()->organization_id, $days, $basis, now());

        if ($format = ReportExporter::requestedFormat($request)) {
            return $this->exporter->download(
                $format,
                'Dead Stock',
                ['Product', 'SKU', 'Category', 'Location', 'On hand', 'Unit cost', 'Tied-up value', 'Last sale', 'Last outbound movement'],
                array_map(fn (array $r) => [
                    $r['name'], $r['sku'], $r['category'], $r['location'], $r['stock'],
                    $r['unit_cost'], $r['tied_up_value'], $r['last_sale_at'], $r['last_outbound_at'],
                ], $result['rows']),
                [
                    "No activity in the last {$days} days (basis: {$basis}).",
                    'Tied-up value is on-hand quantity at the current purchase price.',
                ]
            );
        }

        return Inertia::render('Reports/DeadStock', [
            'rows' => $result['rows'],
            'summary' => $result['summary'],
            'truncated' => $result['truncated'],
            'filters' => ['days' => $days, 'basis' => $basis],
            'options' => ['days' => InventoryAnalyticsService::DEAD_STOCK_DAYS, 'bases' => InventoryAnalyticsService::DEAD_STOCK_BASES],
        ]);
    }

    public function inventoryTurnover(Request $request): Response|HttpResponse
    {
        $period = ReportPeriod::fromRequest($request, 90);
        $result = $this->analytics->inventoryTurnover($request->user()->organization_id, $period);

        if ($format = ReportExporter::requestedFormat($request)) {
            $notes = [
                'Period: '.$period->label().' ('.$period->days().' days).',
                'Average inventory = (opening + closing on-hand) / 2, reconstructed from current stock and the stock adjustment history, valued at current purchase price.',
                'COGS = units sold x current purchase price.',
            ];
            $figures = fn (array $r) => [$r['average_value'], $r['cogs'], $r['turnover'], $r['days_of_inventory']];

            if ($request->query('group') === 'category') {
                return $this->exporter->download(
                    $format,
                    'Inventory Turnover by Category',
                    ['Category', 'Products', 'Units sold', 'Avg inventory value', 'COGS', 'Turnover', 'Days of inventory'],
                    array_map(fn (array $r) => [$r['category'] ?? 'Uncategorized', $r['products'], $r['units_sold'], ...$figures($r)], $result['categories']),
                    $notes
                );
            }

            return $this->exporter->download(
                $format,
                'Inventory Turnover',
                ['Product', 'SKU', 'Category', 'Units sold', 'Opening units', 'Closing units', 'Avg inventory value', 'COGS', 'Turnover', 'Days of inventory'],
                array_map(fn (array $r) => [
                    $r['name'], $r['sku'], $r['category'], $r['units_sold'], $r['opening_units'], $r['closing_units'], ...$figures($r),
                ], $result['products']),
                $notes
            );
        }

        return Inertia::render('Reports/InventoryTurnover', $result + [
            'filters' => $period->toFilters(),
        ]);
    }

    public function profitMargin(Request $request): Response|HttpResponse
    {
        $period = ReportPeriod::fromRequest($request);
        $result = $this->analytics->profitMargin($request->user()->organization_id, $period);

        if ($format = ReportExporter::requestedFormat($request)) {
            $notes = [
                'Period: '.$period->label().'. Revenue is order line subtotals before tax, cancelled orders excluded.',
                'COGS uses the CURRENT purchase price: order lines do not store cost at the time of sale.',
            ];
            $figures = fn (array $r) => [$r['units'], $r['revenue'], $r['cogs'], $r['margin'], $r['margin_pct'], $r['units_without_cost']];

            if ($request->query('group') === 'category') {
                return $this->exporter->download(
                    $format,
                    'Profit Margin by Category',
                    ['Category', 'Units', 'Revenue', 'COGS', 'Gross margin', 'Margin %', 'Units without cost'],
                    array_map(fn (array $r) => [$r['category'] ?? 'Uncategorized', ...$figures($r)], $result['categories']),
                    $notes
                );
            }

            return $this->exporter->download(
                $format,
                'Profit Margin',
                ['Product', 'SKU', 'Category', 'Units', 'Revenue', 'COGS', 'Gross margin', 'Margin %', 'Units without cost'],
                array_map(fn (array $r) => [$r['name'], $r['sku'], $r['category'], ...$figures($r)], $result['products']),
                $notes
            );
        }

        return Inertia::render('Reports/ProfitMargin', [
            'products' => $result['products'],
            'categories' => $result['categories'],
            'summary' => $result['summary'],
            'costBasis' => $result['cost_basis'],
            'truncated' => $result['truncated'],
            'filters' => $period->toFilters(),
        ]);
    }

    public function salesByLocation(Request $request): Response|HttpResponse
    {
        $period = ReportPeriod::fromRequest($request);
        $result = $this->analytics->salesByLocation($request->user()->organization_id, $period);

        if ($format = ReportExporter::requestedFormat($request)) {
            $notes = [
                'Period: '.$period->label().', compared with '.$result['previousPeriod']['date_from'].' to '.$result['previousPeriod']['date_to'].'.',
                'Sales are order totals including tax; cancelled orders excluded.',
            ];

            if ($request->query('group') === 'location') {
                return $this->exporter->download(
                    $format,
                    'Sales by Location',
                    ['Location', 'Warehouse', 'Orders', 'Units', 'Sales', 'Previous units', 'Previous sales', 'Change %'],
                    array_map(fn (array $r) => [
                        $r['name'] ?? 'No location', $r['warehouse'], $r['orders'], $r['units'], $r['revenue'],
                        $r['previous_units'], $r['previous_revenue'], $r['delta_pct'],
                    ], $result['byLocation']),
                    $notes
                );
            }

            return $this->exporter->download(
                $format,
                'Sales by Warehouse',
                ['Warehouse', 'Orders', 'Sales', 'Previous orders', 'Previous sales', 'Change %'],
                array_map(fn (array $r) => [
                    $r['name'] ?? 'No warehouse', $r['orders'], $r['revenue'], $r['previous_orders'], $r['previous_revenue'], $r['delta_pct'],
                ], $result['byWarehouse']),
                $notes
            );
        }

        return Inertia::render('Reports/SalesByLocation', $result + [
            'filters' => $period->toFilters(),
        ]);
    }

    public function abcAnalysis(Request $request): Response|HttpResponse
    {
        $period = ReportPeriod::fromRequest($request, 90);
        $result = $this->analytics->abcAnalysis($request->user()->organization_id, $period);

        if ($format = ReportExporter::requestedFormat($request)) {
            return $this->exporter->download(
                $format,
                'ABC Analysis',
                ['Rank', 'Product', 'SKU', 'Units', 'Revenue', 'Share %', 'Cumulative %', 'Class'],
                array_map(fn (array $r, int $i) => [
                    $i + 1, $r['name'], $r['sku'], $r['units'], $r['revenue'], $r['share_pct'], $r['cumulative_pct'], $r['class'],
                ], $result['rows'], array_keys($result['rows'])),
                [
                    'Period: '.$period->label().'. Revenue is order line subtotals before tax.',
                    'Class A: first 80% of revenue. Class B: next 15%. Class C: final 5%.',
                ]
            );
        }

        return Inertia::render('Reports/AbcAnalysis', $result + [
            'thresholds' => ['A' => InventoryAnalyticsService::ABC_A_THRESHOLD, 'B' => InventoryAnalyticsService::ABC_B_THRESHOLD],
            'filters' => $period->toFilters(),
        ]);
    }
}
