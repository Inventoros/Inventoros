<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Dedoc\Scramble\Attributes\QueryParameter;

/**
 * @tags Orders
 */
class OrderController extends Controller
{
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
     * @param Request $request The incoming HTTP request containing order data
     * @return JsonResponse
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

        return DB::transaction(function () use ($validated, $organizationId) {
            // Create the order
            $order = Order::create([
                'organization_id' => $organizationId,
                'order_number' => Order::generateOrderNumber($organizationId),
                'source' => $validated['source'] ?? 'api',
                'external_id' => $validated['external_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'] ?? null,
                'customer_address' => $validated['customer_address'] ?? null,
                'status' => $validated['status'] ?? 'pending',
                'currency' => $validated['currency'] ?? 'USD',
                'order_date' => $validated['order_date'] ?? now(),
                'notes' => $validated['notes'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
                'subtotal' => 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => 0,
            ]);

            $subtotal = 0;
            $totalTax = 0;

            // Create order items
            foreach ($validated['items'] as $itemData) {
                $product = Product::where('id', $itemData['product_id'])
                    ->where('organization_id', $organizationId)
                    ->lockForUpdate()
                    ->first();

                if (!$product || $product->stock < $itemData['quantity']) {
                    $productName = $product ? $product->name : "ID {$itemData['product_id']}";
                    $available = $product ? $product->stock : 0;
                    return response()->json([
                        'message' => "Insufficient stock for {$productName}. Available: {$available}, Requested: {$itemData['quantity']}",
                        'error' => 'insufficient_stock',
                    ], 422);
                }

                $unitPrice = $itemData['unit_price'] ?? $product->selling_price ?? $product->price ?? 0;
                $itemSubtotal = $unitPrice * $itemData['quantity'];
                $itemTax = $itemData['tax'] ?? 0;
                $itemTotal = $itemSubtotal + $itemTax;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                    'tax' => $itemTax,
                    'total' => $itemTotal,
                ]);

                // Decrement product stock
                $product->decrement('stock', $itemData['quantity']);

                $subtotal += $itemSubtotal;
                $totalTax += $itemTax;
            }

            // Update order totals
            $order->update([
                'subtotal' => $subtotal,
                'tax' => $totalTax,
                'total' => $subtotal + $totalTax,
            ]);

            $order->load('items');

            return response()->json([
                'message' => 'Order created successfully',
                'data' => new OrderResource($order),
            ], 201);
        });
    }

    /**
     * Display the specified order.
     *
     * @param Request $request The incoming HTTP request
     * @param Order $order The order to display
     * @return JsonResponse
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
     * @param Request $request The incoming HTTP request containing updated order data
     * @param Order $order The order to update
     * @return JsonResponse
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

        // Handle status change timestamps
        if (isset($validated['status'])) {
            if ($validated['status'] === 'shipped' && !$order->shipped_at) {
                $validated['shipped_at'] = now();
            }
            if ($validated['status'] === 'delivered' && !$order->delivered_at) {
                $validated['delivered_at'] = now();
            }
        }

        $order->update($validated);
        $order->load('items');

        return response()->json([
            'message' => 'Order updated successfully',
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * Remove the specified order.
     *
     * @param Request $request The incoming HTTP request
     * @param Order $order The order to delete
     * @return JsonResponse
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
            // Restore stock for each item
            foreach ($order->items as $item) {
                if ($item->product) {
                    $item->product->increment('stock', $item->quantity);
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
