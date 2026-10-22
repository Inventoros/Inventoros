<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\StockBalanceService;
use App\Support\SpreadsheetSafety;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockBalanceController extends Controller
{
    private const FILTERS = ['search', 'warehouse_id', 'location_id', 'out_of_stock', 'include_zero', 'sort_by', 'sort_dir', 'per_page', 'page'];

    public function index(Request $request, StockBalanceService $stock): Response
    {
        $filters = $request->only(self::FILTERS);
        if (! array_key_exists('warehouse_id', $filters)) {
            $filters['warehouse_id'] = $request->session()->get('active_warehouse_id');
        }

        return Inertia::render('Stock/Index', ['balances' => $stock->paginate($request->user(), $filters), 'filters' => $filters]);
    }

    public function api(Request $request, StockBalanceService $stock): JsonResponse
    {
        return response()->json($stock->paginate($request->user(), $request->only(self::FILTERS)));
    }

    public function export(Request $request, StockBalanceService $stock): StreamedResponse
    {
        $query = $stock->query($request->user(), $request->only(self::FILTERS));

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['SKU', 'Product', 'Variant', 'Warehouse', 'Location', 'On hand', 'Reserved', 'Available', 'In transit'], escape: '');
            foreach ($query->cursor() as $row) {
                $cells = [$row->sku, $row->product_name, $row->variant_title, $row->warehouse_name, $row->location_name, (int) $row->on_hand, (int) $row->reserved, (int) $row->available, (int) $row->in_transit];
                fputcsv($output, array_map(SpreadsheetSafety::neutralise(...), $cells), escape: '');
            }
            fclose($output);
        }, 'warehouse-stock-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
