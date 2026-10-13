<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WorkOrder\StoreWorkOrderRequest;
use App\Models\Inventory\WorkOrder;
use App\Services\WorkOrderService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Work Orders
 */
class WorkOrderController extends Controller
{
    /**
     * List work orders.
     */
    #[QueryParameter('search', description: 'Search by WO number, product name, or SKU', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['draft', 'pending', 'in_progress', 'completed', 'cancelled'])]
    #[QueryParameter('sort_by', description: 'Sort field (default: created_at)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $query = WorkOrder::with(['product:id,name,sku,thumbnail', 'creator:id,name'])
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('work_order_number', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($q2) use ($search) {
                            $q2->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            });

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ['created_at', 'updated_at', 'work_order_number', 'status', 'quantity'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'created_at';
        $sortDir = ($request->input('sort_dir') === 'asc') ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $workOrders = $query->paginate($perPage);

        return response()->json($workOrders);
    }

    /**
     * Store a newly created work order.
     *
     * Creates a work order for an assembly product and auto-populates
     * items from the product's bill of materials. A virtual kit in the BOM
     * is exploded into its own components. Name the output variant with
     * `product_variant_id` and the variant of each component stocked by
     * variant with `component_variants` (`variant_required` /
     * `invalid_variant` otherwise).
     *
     * @param  Request  $request  The incoming HTTP request containing work order data
     */
    public function store(StoreWorkOrderRequest $request, WorkOrderService $workOrders): JsonResponse
    {
        try {
            $workOrder = $workOrders->create($request->user(), $request->validated());
        } catch (InvalidStateException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->errorCode], 422);
        }

        $workOrder->load(['product:id,name,sku,thumbnail', 'variant:id,product_id,title,sku', 'creator:id,name', 'items.product:id,name,sku,stock', 'items.variant:id,product_id,title,sku,stock']);

        return response()->json([
            'message' => 'Work order created successfully',
            'data' => $workOrder,
        ], 201);
    }

    /**
     * Display the specified work order.
     *
     * Includes each component line's variant and the serials / batch
     * quantities it consumed (`items.*.allocations`), and what the order
     * produced (`outputs`).
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to display
     */
    public function show(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Work order not found',
                'error' => 'not_found',
            ], 404);
        }

        $workOrder->load([
            'product:id,name,sku,thumbnail,type,tracking_type',
            'variant:id,product_id,title,sku,stock',
            'creator:id,name',
            'items.product:id,name,sku,stock,thumbnail,tracking_type',
            'items.variant:id,product_id,title,sku,stock',
            'items.kit:id,name,sku',
            'items.allocations.serial:id,serial_number',
            'items.allocations.batch:id,batch_number,expiry_date',
            'outputs.variant:id,title,sku',
            'outputs.serial:id,serial_number',
            'outputs.batch:id,batch_number,expiry_date',
        ]);

        return response()->json([
            'data' => $workOrder,
        ]);
    }

    /**
     * Start a work order.
     *
     * Changes status from draft/pending to in_progress after verifying
     * all components have sufficient stock, and enough available serials or
     * batch quantity when they are tracked.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to start
     */
    public function start(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Work order not found',
                'error' => 'not_found',
            ], 404);
        }

        try {
            app(WorkOrderService::class)->start($workOrder);
        } catch (BusinessRuleException $e) {
            return $this->refusal($e);
        }

        $workOrder->refresh()->load(['product:id,name,sku,thumbnail', 'creator:id,name']);

        return response()->json([
            'message' => 'Work order started successfully',
            'data' => $workOrder,
        ]);
    }

    /**
     * Complete a work order.
     *
     * Consumes components in proportion to `quantity_produced` and receives
     * the output, all or nothing. For a serial- or batch-tracked component,
     * `allocations` may name the serials (`serial_ids`) or batch quantities
     * (`batches`) to consume per line; lines left out are allocated oldest
     * serial first / first-expiry-first. A serial-tracked output needs one
     * `output.serial_numbers` entry per unit produced; a batch-tracked output
     * goes into `output.batch_number` (topped up when it exists, generated
     * when omitted) with an optional `output.expiry_date`.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to complete
     */
    public function complete(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Work order not found',
                'error' => 'not_found',
            ], 404);
        }

        if ($workOrder->status !== 'in_progress') {
            return response()->json([
                'message' => 'Only in-progress work orders can be completed.',
                'error' => 'invalid_status',
            ], 422);
        }

        $validated = $request->validate(WorkOrderService::completionRules($workOrder->quantity));

        try {
            $quantityProduced = app(WorkOrderService::class)->complete($workOrder, $validated);
        } catch (BusinessRuleException $e) {
            return $this->refusal($e);
        }

        $workOrder->refresh()->load(['product:id,name,sku,thumbnail', 'creator:id,name', 'items.allocations', 'outputs']);

        return response()->json([
            'message' => "Work order completed. {$quantityProduced} units produced.",
            'data' => $workOrder,
        ]);
    }

    /**
     * Cancel a work order.
     *
     * Stock only moves when a work order completes, so cancelling returns
     * nothing unless the order recorded consumption (older data), in which
     * case exactly that is returned. Completed orders cannot be cancelled.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to cancel
     */
    public function cancel(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Work order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (! in_array($workOrder->status, ['draft', 'pending', 'in_progress'])) {
            return response()->json([
                'message' => 'Only draft, pending, or in-progress work orders can be cancelled.',
                'error' => 'invalid_status',
            ], 422);
        }

        try {
            app(WorkOrderService::class)->cancel($workOrder);
        } catch (BusinessRuleException $e) {
            return $this->refusal($e);
        }

        $workOrder->refresh()->load(['product:id,name,sku,thumbnail', 'creator:id,name']);

        return response()->json([
            'message' => 'Work order has been cancelled',
            'data' => $workOrder,
        ]);
    }

    /**
     * Delete a work order.
     *
     * Only draft or cancelled work orders, which have never moved stock, can
     * be deleted; anything else is a 422 `invalid_status`.
     */
    public function destroy(Request $request, WorkOrder $workOrder, WorkOrderService $workOrders): JsonResponse
    {
        if ($workOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Work order not found',
                'error' => 'not_found',
            ], 404);
        }

        try {
            $workOrders->delete($workOrder);
        } catch (InvalidStateException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => $e->errorCode], 422);
        }

        return response()->json(['message' => 'Work order deleted successfully']);
    }

    /**
     * The 422 for a refused transition: insufficient_stock for a shortfall,
     * otherwise the business rule's own error code.
     */
    private function refusal(BusinessRuleException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error' => match (true) {
                $e instanceof InsufficientStockException => 'insufficient_stock',
                $e instanceof InvalidStateException => $e->errorCode,
                default => 'invalid_status',
            },
        ], 422);
    }
}
