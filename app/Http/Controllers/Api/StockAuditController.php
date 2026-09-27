<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StockAudit\RecordStockAuditCountRequest;
use App\Http\Requests\Api\StockAudit\StoreStockAuditRequest;
use App\Http\Resources\StockAuditItemResource;
use App\Http\Resources\StockAuditResource;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Services\StockAuditService;
use App\Services\WarehouseAccessService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Stock Audits
 */
class StockAuditController extends Controller
{
    use HandlesApiResponses;

    /**
     * List stock audits.
     */
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['draft', 'in_progress', 'completed', 'cancelled'])]
    #[QueryParameter('audit_type', description: 'Filter by audit type', type: 'string', enum: ['full', 'cycle', 'spot'])]
    #[QueryParameter('sort_by', description: 'Sort field (default: created_at)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAudit::with(['warehouseLocation', 'creator'])
            ->withCount('items')
            ->forOrganization($organizationId)
            ->tap(fn ($q) => app(WarehouseAccessService::class)->scopeByLocation($q, $request->user(), 'warehouse_location_id'))
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            })
            ->when($request->input('audit_type'), function ($query, $type) {
                $query->byType($type);
            });

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ['created_at', 'updated_at', 'audit_number', 'status', 'started_at', 'completed_at'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'created_at';
        $sortDir = ($request->input('sort_dir') === 'asc') ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $audits = $query->paginate($perPage);

        return StockAuditResource::collection($audits);
    }

    /**
     * Display the specified stock audit.
     *
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit to display
     * @return JsonResponse
     */
    public function show(Request $request, StockAudit $stockAudit): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Stock audit not found',
                'error' => 'not_found',
            ], 404);
        }

        app(WarehouseAccessService::class)->authorizeLocation($request->user(), $stockAudit->warehouse_location_id);

        $stockAudit->load([
            'warehouseLocation',
            'creator',
            'items.product',
            'items.variant',
            'items.location',
            'items.countedByUser',
        ]);

        return response()->json([
            'data' => new StockAuditResource($stockAudit),
        ]);
    }

    /**
     * Create a draft stock audit.
     *
     * Items are seeded from `product_ids` when given, otherwise from the
     * products at `warehouse_location_id`, otherwise from every active product.
     */
    public function store(StoreStockAuditRequest $request, StockAuditService $audits): JsonResponse
    {
        $audit = $audits->create($request->user()->organization_id, $request->user(), $request->validated());

        return response()->json([
            'message' => 'Stock audit created successfully',
            'data' => new StockAuditResource($this->loaded($audit)),
        ], 201);
    }

    /**
     * Start a draft audit, snapshotting current system quantities.
     */
    public function start(Request $request, StockAudit $stockAudit, StockAuditService $audits): JsonResponse
    {
        $this->ensureOwned($request, $stockAudit, 'Stock audit');

        try {
            $audits->start($stockAudit, $request->user());
        } catch (\RuntimeException $e) {
            return $this->stateError($e);
        }

        return response()->json([
            'message' => 'Stock audit started',
            'data' => new StockAuditResource($this->loaded($stockAudit->fresh())),
        ]);
    }

    /**
     * Record the physical count for one item of an in-progress audit.
     */
    public function recordCount(RecordStockAuditCountRequest $request, StockAudit $stockAudit, StockAuditItem $item, StockAuditService $audits): JsonResponse
    {
        $this->ensureOwned($request, $stockAudit, 'Stock audit');

        $validated = $request->validated();

        try {
            $audits->recordCount($stockAudit, $item, $request->user(), (int) $validated['counted_quantity'], $validated['notes'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->stateError($e);
        }

        return response()->json([
            'message' => 'Count recorded',
            'data' => new StockAuditItemResource($item->fresh(['product', 'countedByUser'])),
        ]);
    }

    /**
     * Complete an in-progress audit, booking a recount stock adjustment for
     * every counted item that differs from its system quantity.
     */
    public function complete(Request $request, StockAudit $stockAudit, StockAuditService $audits): JsonResponse
    {
        $this->ensureOwned($request, $stockAudit, 'Stock audit');

        try {
            $adjustmentsCreated = $audits->complete($stockAudit, $request->user());
        } catch (\RuntimeException $e) {
            return $this->stateError($e);
        }

        return response()->json([
            'message' => 'Stock audit completed',
            'adjustments_created' => $adjustmentsCreated,
            'data' => new StockAuditResource($this->loaded($stockAudit->fresh())),
        ]);
    }

    private function loaded(StockAudit $audit): StockAudit
    {
        return $audit->load(['warehouseLocation', 'creator', 'items.product', 'items.location', 'items.countedByUser']);
    }
}
