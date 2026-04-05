<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Dedoc\Scramble\Attributes\QueryParameter;

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
     * items from the product's bill of materials.
     *
     * @param Request $request The incoming HTTP request containing work order data
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('organization_id', $organizationId),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Verify the product is an assembly type
        $product = Product::where('id', $validated['product_id'])
            ->forOrganization($organizationId)
            ->firstOrFail();

        if ($product->type !== 'assembly') {
            return response()->json([
                'message' => 'Work orders can only be created for assembly products.',
                'error' => 'invalid_product_type',
            ], 422);
        }

        // Load the product's components
        $components = $product->components()->with('componentProduct')->get();

        if ($components->isEmpty()) {
            return response()->json([
                'message' => 'This assembly has no components defined. Add components before creating a work order.',
                'error' => 'no_components',
            ], 422);
        }

        $workOrder = DB::transaction(function () use ($validated, $organizationId, $request, $components) {
            $workOrder = WorkOrder::create([
                'organization_id' => $organizationId,
                'product_id' => $validated['product_id'],
                'created_by' => $request->user()->id,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'work_order_number' => WorkOrder::generateWorkOrderNumber($organizationId),
                'quantity' => $validated['quantity'],
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
            ]);

            // Auto-populate items from the assembly's BOM * requested quantity
            foreach ($components as $component) {
                WorkOrderItem::create([
                    'work_order_id' => $workOrder->id,
                    'product_id' => $component->component_product_id,
                    'quantity_required' => $component->quantity * $validated['quantity'],
                    'quantity_consumed' => 0,
                ]);
            }

            return $workOrder;
        });

        $workOrder->load(['product:id,name,sku,thumbnail', 'creator:id,name', 'items.product:id,name,sku,stock']);

        return response()->json([
            'message' => 'Work order created successfully',
            'data' => $workOrder,
        ], 201);
    }

    /**
     * Display the specified work order.
     *
     * @param Request $request The incoming HTTP request
     * @param WorkOrder $workOrder The work order to display
     * @return JsonResponse
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
            'product:id,name,sku,thumbnail,type',
            'creator:id,name',
            'items.product:id,name,sku,stock,thumbnail',
        ]);

        return response()->json([
            'data' => $workOrder,
        ]);
    }

    /**
     * Start a work order.
     *
     * Changes status from draft/pending to in_progress after verifying
     * all components have sufficient stock.
     *
     * @param Request $request The incoming HTTP request
     * @param WorkOrder $workOrder The work order to start
     * @return JsonResponse
     */
    public function start(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Work order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (!in_array($workOrder->status, ['draft', 'pending'])) {
            return response()->json([
                'message' => 'Only draft or pending work orders can be started.',
                'error' => 'invalid_status',
            ], 422);
        }

        // Check component stock availability
        $workOrder->load('items.product');

        foreach ($workOrder->items as $item) {
            $remaining = $item->quantity_required - $item->quantity_consumed;
            if ($item->product->stock < $remaining) {
                return response()->json([
                    'message' => "Insufficient stock for component '{$item->product->name}'. Available: {$item->product->stock}, Required: {$remaining}.",
                    'error' => 'insufficient_stock',
                ], 422);
            }
        }

        $workOrder->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $workOrder->load(['product:id,name,sku,thumbnail', 'creator:id,name']);

        return response()->json([
            'message' => 'Work order started successfully',
            'data' => $workOrder,
        ]);
    }

    /**
     * Complete a work order.
     *
     * Decrements component stock and increments assembly product stock.
     *
     * @param Request $request The incoming HTTP request
     * @param WorkOrder $workOrder The work order to complete
     * @return JsonResponse
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

        $validated = $request->validate([
            'quantity_produced' => ['nullable', 'integer', 'min:1', 'max:' . $workOrder->quantity],
        ]);

        $quantityProduced = $validated['quantity_produced'] ?? $workOrder->quantity;

        DB::transaction(function () use ($workOrder, $quantityProduced) {
            $workOrder->load('items.product');

            // Decrement component stock
            foreach ($workOrder->items as $item) {
                $consumeQty = $item->quantity_required - $item->quantity_consumed;
                $consumeQtyInt = (int) ceil($consumeQty);

                if ($consumeQtyInt > 0) {
                    StockAdjustment::adjust(
                        $item->product,
                        -$consumeQtyInt,
                        'assembly_consumption',
                        "Consumed for work order {$workOrder->work_order_number}",
                        "Assembly of {$workOrder->product->name}",
                        $workOrder
                    );

                    $item->update(['quantity_consumed' => $item->quantity_required]);
                }
            }

            // Increment assembly product stock
            $assemblyProduct = $workOrder->product;
            StockAdjustment::adjust(
                $assemblyProduct,
                $quantityProduced,
                'assembly_production',
                "Produced from work order {$workOrder->work_order_number}",
                "Assembled {$quantityProduced} units",
                $workOrder
            );

            $workOrder->update([
                'status' => 'completed',
                'quantity_produced' => $quantityProduced,
                'completed_at' => now(),
            ]);
        });

        $workOrder->refresh()->load(['product:id,name,sku,thumbnail', 'creator:id,name']);

        return response()->json([
            'message' => "Work order completed. {$quantityProduced} units produced.",
            'data' => $workOrder,
        ]);
    }

    /**
     * Cancel a work order.
     *
     * If the work order was in progress, restores any consumed component stock.
     *
     * @param Request $request The incoming HTTP request
     * @param WorkOrder $workOrder The work order to cancel
     * @return JsonResponse
     */
    public function cancel(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Work order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (!in_array($workOrder->status, ['draft', 'pending', 'in_progress'])) {
            return response()->json([
                'message' => 'Only draft, pending, or in-progress work orders can be cancelled.',
                'error' => 'invalid_status',
            ], 422);
        }

        DB::transaction(function () use ($workOrder) {
            // If in_progress, restore any consumed component stock
            if ($workOrder->status === 'in_progress') {
                $workOrder->load('items.product');

                foreach ($workOrder->items as $item) {
                    $consumedQty = (int) ceil((float) $item->quantity_consumed);

                    if ($consumedQty > 0) {
                        StockAdjustment::adjust(
                            $item->product,
                            $consumedQty,
                            'assembly_reversal',
                            "Reversed for cancelled work order {$workOrder->work_order_number}",
                            "Work order cancelled - restoring consumed stock",
                            $workOrder
                        );

                        $item->update(['quantity_consumed' => 0]);
                    }
                }
            }

            $workOrder->update(['status' => 'cancelled']);
        });

        $workOrder->refresh()->load(['product:id,name,sku,thumbnail', 'creator:id,name']);

        return response()->json([
            'message' => 'Work order has been cancelled',
            'data' => $workOrder,
        ]);
    }
}
