<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StockTransfer\ShipStockTransferRequest;
use App\Http\Requests\Api\StockTransfer\StoreStockTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\Inventory\StockTransfer;
use App\Services\StockTransferService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stock transfers between locations. Every transition runs through
 * StockTransferService, the same implementation the web controller uses.
 *
 * @tags Stock Transfers
 */
class StockTransferController extends Controller
{
    use HandlesApiResponses;

    public function __construct(private readonly StockTransferService $transfers) {}

    /**
     * List stock transfers.
     */
    #[QueryParameter('search', description: 'Search by transfer number', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['pending', 'in_transit', 'completed', 'cancelled'])]
    #[QueryParameter('warehouse_id', description: 'Only transfers into or out of this warehouse', type: 'integer')]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $transfers = StockTransfer::with(['fromLocation', 'toLocation', 'transferredBy'])
            ->withCount('items')
            ->forOrganization($request->user()->organization_id)
            ->when($request->input('warehouse_id'), function ($query, $warehouseId) {
                $query->where(function ($q) use ($warehouseId) {
                    $q->where('from_warehouse_id', $warehouseId)
                        ->orWhere('to_warehouse_id', $warehouseId)
                        ->orWhereHas('fromLocation', fn ($l) => $l->where('warehouse_id', $warehouseId))
                        ->orWhereHas('toLocation', fn ($l) => $l->where('warehouse_id', $warehouseId));
                });
            })
            ->when($request->input('search'), fn ($query, $search) => $query->where('transfer_number', 'like', "%{$search}%"))
            ->when($request->input('status'), fn ($query, $status) => $query->byStatus($status))
            ->latest()
            ->latest('id')
            ->paginate($this->perPage($request));

        return StockTransferResource::collection($transfers);
    }

    /**
     * Create a pending stock transfer.
     */
    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $transfer = $this->transfers->create($request->user()->organization_id, $request->user(), $request->validated());

        return response()->json([
            'message' => 'Stock transfer created successfully',
            'data' => new StockTransferResource($this->loaded($transfer)),
        ], 201);
    }

    /**
     * Show a stock transfer.
     */
    public function show(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->ensureOwned($request, $stockTransfer, 'Stock transfer');

        return response()->json(['data' => new StockTransferResource($this->loaded($stockTransfer))]);
    }

    /**
     * Mark a pending transfer as shipped (in transit).
     */
    public function ship(ShipStockTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->transition($request, $stockTransfer, fn () => $this->transfers->ship($stockTransfer, $request->validated()), 'Stock transfer marked as in transit');
    }

    /**
     * Complete a pending or in-transit transfer, moving each line's quantity
     * from the source location bin to the destination bin. A short source
     * bin is a 422.
     */
    public function complete(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->transition($request, $stockTransfer, fn () => $this->transfers->complete($stockTransfer, $request->user()), 'Stock transfer completed', 'insufficient_stock');
    }

    /**
     * Cancel a pending or in-transit transfer.
     */
    public function cancel(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->transition($request, $stockTransfer, fn () => $this->transfers->cancel($stockTransfer), 'Stock transfer cancelled');
    }

    /**
     * @param  callable(): StockTransfer  $action
     */
    private function transition(Request $request, StockTransfer $stockTransfer, callable $action, string $message, string $fallbackCode = 'invalid_state'): JsonResponse
    {
        $this->ensureOwned($request, $stockTransfer, 'Stock transfer');

        try {
            $updated = $action();
        } catch (\RuntimeException $e) {
            return $this->stateError($e, $fallbackCode);
        }

        return response()->json([
            'message' => $message,
            'data' => new StockTransferResource($this->loaded($updated->fresh())),
        ]);
    }

    private function loaded(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load(['fromLocation', 'toLocation', 'transferredBy', 'items.product']);
    }
}
