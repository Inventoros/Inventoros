<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAudit\ListStockAuditItemsRequest;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Services\StockAuditReadService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StockAuditItemsController extends Controller
{
    private function authorizeAudit(Request $request, StockAudit $audit, ?StockAuditItem $item = null): void
    {
        abort_unless((int) $audit->organization_id === (int) $request->user()->organization_id, 404);
        app(WarehouseAccessService::class)->authorizeStockAudit($request->user(), $audit);
        abort_if($item !== null && (int) $item->stock_audit_id !== (int) $audit->id, 404);
    }

    public function index(ListStockAuditItemsRequest $request, StockAudit $stockAudit, StockAuditReadService $read): JsonResponse
    {
        $this->authorizeAudit($request, $stockAudit);
        $items = $read->paginate($stockAudit, $request->validated())->through(fn ($line) => $read->present($line));

        return response()->json($items->toArray() + ['summary' => $read->summary($stockAudit)]);
    }

    public function show(Request $request, StockAudit $stockAudit, StockAuditItem $item, StockAuditReadService $read): JsonResponse
    {
        $this->authorizeAudit($request, $stockAudit, $item);

        return response()->json(['data' => $read->present($item), 'summary' => $read->summary($stockAudit)]);
    }

    public function lookup(Request $request, StockAudit $stockAudit, StockAuditReadService $read): JsonResponse
    {
        $this->authorizeAudit($request, $stockAudit);
        $data = $request->validate(['code' => ['required', 'string', 'max:2048'], 'location_id' => ['nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'in:25,50,100']]);

        return response()->json($read->lookup($stockAudit, $data['code'], isset($data['location_id']) ? (int) $data['location_id'] : null, (int) ($data['per_page'] ?? 25)));
    }
}
