<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Services\OrderService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @tags Orders
 */
class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
    ) {}

    /**
     * List orders.
     */
    #[QueryParameter('search', description: 'Search by order number, customer name, or email', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status', type: 'string', enum: ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])]
    #[QueryParameter('source', description: 'Filter by order source', type: 'string')]
    #[QueryParameter('warehouse_id', description: 'Filter by warehouse ID', type: 'integer')]
    #[QueryParameter('date_from', description: 'Filter orders from this date (YYYY-MM-DD)', type: 'string', example: '2025-01-01')]
    #[QueryParameter('date_to', description: 'Filter orders until this date (YYYY-MM-DD)', type: 'string', example: '2025-12-31')]
    #[QueryParameter('sort_by', description: 'Sort field (default: created_at)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = Order::withCount('items')
            ->forOrganization($organizationId)
            ->when($request->input('warehouse_id'), function ($query, $warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            })
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%");
                });
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            })
            ->when($request->input('source'), function ($query, $source) {
                $query->bySource($source);
            })
            ->when($request->input('date_from'), function ($query, $dateFrom) {
                $query->where('order_date', '>=', $dateFrom);
            })
            ->when($request->input('date_to'), function ($query, $dateTo) {
                $query->where('order_date', '<=', $dateTo);
            });

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ['created_at', 'updated_at', 'order_number', 'customer_name', 'total', 'status', 'order_date'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'created_at';
        $sortDir = ($request->input('sort_dir') === 'asc') ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $orders = $query->paginate($perPage);

        return OrderResource::collection($orders);
    }

    /**
     * Store a newly created order.
     *
     * @param  Request  $request  The incoming HTTP request containing order data
     */
    public function store(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'source' => ['nullable', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_address' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:pending,processing,shipped,delivered,cancelled'],
            'currency' => ['nullable', 'string', 'max:3'],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $organizationId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0'],
        ]);

        // API-specific defaults; the OrderService owns the create invariant
        // (lock → validate → ledger + decrement, wrapped in SequenceNumberRetry)
        // shared with the web/GraphQL/MCP surfaces.
        $validated['status'] ??= 'pending';
        $validated['order_date'] ??= now();
        $validated['currency'] ??= 'USD';

        try {
            $order = $this->orderService->create(
                $validated,
                $request->user(),
                $validated['source'] ?? 'api'
            );
        } catch (InsufficientStockException $e) {
            // Preserve the API's 422 contract: insufficient stock surfaces as
            // an `items` validation error rather than a generic 500.
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        $order->load('items');

        return response()->json([
            'message' => 'Order created successfully',
            'data' => new OrderResource($order),
        ], 201);
    }

    /**
     * Display the specified order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Order  $order  The order to display
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        if ($order->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Order not found',
                'error' => 'not_found',
            ], 404);
        }

        $order->load('items.product');

        return response()->json([
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * Update the specified order.
     *
     * @param  Request  $request  The incoming HTTP request containing updated order data
     * @param  Order  $order  The order to update
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        if ($order->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Order not found',
                'error' => 'not_found',
            ], 404);
        }

        $validated = $request->validate([
            'customer_name' => ['sometimes', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_address' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:pending,processing,shipped,delivered,cancelled'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $cancelTransition = isset($validated['status'])
            && $validated['status'] === 'cancelled'
            && $order->status !== 'cancelled';

        // Reject cancellation of orders that already left the warehouse;
        // restocking would lie about inventory that physically isn't here.
        if ($cancelTransition && in_array($order->status, ['shipped', 'delivered'], true)) {
            return response()->json([
                'message' => "Cannot cancel an order that has already been {$order->status}.",
                'error' => 'invalid_state_transition',
            ], 422);
        }

        // Handle status change timestamps
        if (isset($validated['status'])) {
            if ($validated['status'] === 'shipped' && ! $order->shipped_at) {
                $validated['shipped_at'] = now();
            }
            if ($validated['status'] === 'delivered' && ! $order->delivered_at) {
                $validated['delivered_at'] = now();
            }
        }

        DB::transaction(function () use ($order, $validated, $cancelTransition) {
            if ($cancelTransition) {
                // Mirror Order\OrderController::update — restock through
                // StockAdjustment so the cancellation is auditable and the
                // product row is locked while we increment.
                $order->load('items.product');
                foreach ($order->items as $item) {
                    if ($item->product) {
                        StockAdjustment::adjust(
                            $item->product,
                            $item->quantity,
                            'order_cancellation',
                            "Order {$order->order_number} cancelled via API",
                            null,
                            $order
                        );
                    }
                }
            }

            $order->update($validated);
        });

        $order->load('items');

        return response()->json([
            'message' => 'Order updated successfully',
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * Remove the specified order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Order  $order  The order to delete
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        if ($order->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Order not found',
                'error' => 'not_found',
            ], 404);
        }

        $order->load('items.product');

        return DB::transaction(function () use ($order) {
            // Restore stock for each item through StockAdjustment so the
            // cancellation is reflected in the ledger. adjust() takes
            // lockForUpdate internally so concurrent deletes do not
            // lose increments.
            foreach ($order->items as $item) {
                if ($item->product) {
                    StockAdjustment::adjust(
                        $item->product,
                        $item->quantity,
                        'order_cancellation',
                        "Order {$order->order_number} deleted",
                        null,
                        $order
                    );
                }
            }

            // Delete order items
            $order->items()->delete();

            // Delete order
            $order->delete();

            return response()->json([
                'message' => 'Order deleted successfully',
            ]);
        });
    }
}
