<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Inventory\Product;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of purchase orders.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = PurchaseOrder::with(['supplier', 'creator'])
            ->withCount('items')
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->search($search);
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->byStatus($status);
            })
            ->when($request->input('supplier_id'), function ($query, $supplierId) {
                $query->bySupplier($supplierId);
            });

        // Sorting
        $sortBy = $request->input('sort_by', 'order_date');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $purchaseOrders = $query->paginate($perPage);

        return PurchaseOrderResource::collection($purchaseOrders);
    }

    /**
     * Store a newly created purchase order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['required', 'string', 'max:3'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:255'],
        ]);

        $organizationId = $request->user()->organization_id;

        // Calculate order totals
        $subtotal = 0;
        $orderItems = [];

        foreach ($validated['items'] as $item) {
            $product = Product::forOrganization($organizationId)->findOrFail($item['product_id']);
            $itemSubtotal = $item['quantity'] * $item['unit_cost'];
            $subtotal += $itemSubtotal;

            $orderItems[] = [
                'product_id' => $item['product_id'],
                'product_name' => $product->name,
                'sku' => $product->sku,
                'supplier_sku' => $item['supplier_sku'] ?? null,
                'quantity_ordered' => $item['quantity'],
                'quantity_received' => 0,
                'unit_cost' => $item['unit_cost'],
                'subtotal' => $itemSubtotal,
                'tax' => 0,
                'total' => $itemSubtotal,
            ];
        }

        $purchaseOrder = PurchaseOrder::create([
            'organization_id' => $organizationId,
            'supplier_id' => $validated['supplier_id'],
            'created_by' => $request->user()->id,
            'po_number' => PurchaseOrder::generatePONumber($organizationId),
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => $validated['order_date'],
            'expected_date' => $validated['expected_date'] ?? null,
            'subtotal' => $subtotal,
            'tax' => $validated['tax'] ?? 0,
            'shipping' => $validated['shipping'] ?? 0,
            'total' => $subtotal + ($validated['tax'] ?? 0) + ($validated['shipping'] ?? 0),
            'currency' => $validated['currency'],
            'notes' => $validated['notes'] ?? null,
        ]);

        $purchaseOrder->items()->createMany($orderItems);

        return response()->json([
            'message' => 'Purchase order created successfully',
            'data' => new PurchaseOrderResource($purchaseOrder->load(['supplier', 'items.product'])),
        ], 201);
    }

    /**
     * Display the specified purchase order.
     */
    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        $purchaseOrder->load(['supplier', 'creator', 'items.product']);

        return response()->json([
            'data' => new PurchaseOrderResource($purchaseOrder),
        ]);
    }

    /**
     * Update the specified purchase order.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (!$purchaseOrder->canBeEdited()) {
            return response()->json([
                'message' => 'This purchase order cannot be edited',
                'error' => 'cannot_edit',
            ], 422);
        }

        $validated = $request->validate([
            'supplier_id' => ['sometimes', 'exists:suppliers,id'],
            'order_date' => ['sometimes', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'exists:purchase_order_items,id'],
            'items.*.product_id' => ['required_with:items', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:255'],
        ]);

        $organizationId = $request->user()->organization_id;

        if (isset($validated['items'])) {
            // Handle items update
            $purchaseOrder->load('items');
            $existingItems = $purchaseOrder->items->keyBy('id');
            $itemIdsToKeep = [];
            $subtotal = 0;
            $newItems = [];

            foreach ($validated['items'] as $itemData) {
                $product = Product::forOrganization($organizationId)->findOrFail($itemData['product_id']);
                $itemSubtotal = $itemData['quantity'] * $itemData['unit_cost'];
                $subtotal += $itemSubtotal;

                if (!empty($itemData['id']) && $existingItems->has($itemData['id'])) {
                    $existingItem = $existingItems->get($itemData['id']);
                    $existingItem->update([
                        'product_id' => $itemData['product_id'],
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'supplier_sku' => $itemData['supplier_sku'] ?? null,
                        'quantity_ordered' => $itemData['quantity'],
                        'unit_cost' => $itemData['unit_cost'],
                        'subtotal' => $itemSubtotal,
                        'total' => $itemSubtotal,
                    ]);
                    $itemIdsToKeep[] = $itemData['id'];
                } else {
                    $newItems[] = [
                        'product_id' => $itemData['product_id'],
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'supplier_sku' => $itemData['supplier_sku'] ?? null,
                        'quantity_ordered' => $itemData['quantity'],
                        'quantity_received' => 0,
                        'unit_cost' => $itemData['unit_cost'],
                        'subtotal' => $itemSubtotal,
                        'tax' => 0,
                        'total' => $itemSubtotal,
                    ];
                }
            }

            $existingItems->filter(fn ($item) => !in_array($item->id, $itemIdsToKeep))->each->delete();

            if (!empty($newItems)) {
                $purchaseOrder->items()->createMany($newItems);
            }

            $validated['subtotal'] = $subtotal;
            $validated['total'] = $subtotal + ($validated['tax'] ?? $purchaseOrder->tax) + ($validated['shipping'] ?? $purchaseOrder->shipping);
        }

        unset($validated['items']);
        $purchaseOrder->update($validated);

        return response()->json([
            'message' => 'Purchase order updated successfully',
            'data' => new PurchaseOrderResource($purchaseOrder->fresh()->load(['supplier', 'items.product'])),
        ]);
    }

    /**
     * Remove the specified purchase order.
     */
    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return response()->json([
                'message' => 'Only draft purchase orders can be deleted',
                'error' => 'cannot_delete',
            ], 422);
        }

        $purchaseOrder->delete();

        return response()->json([
            'message' => 'Purchase order deleted successfully',
        ]);
    }

    /**
     * Receive items for a purchase order.
     */
    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (!$purchaseOrder->canReceiveItems()) {
            return response()->json([
                'message' => 'This purchase order cannot receive items',
                'error' => 'cannot_receive',
            ], 422);
        }

        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'exists:purchase_order_items,id'],
            'items.*.quantity_to_receive' => ['required', 'integer', 'min:0'],
        ]);

        $receivedCount = 0;

        foreach ($validated['items'] as $itemData) {
            if ($itemData['quantity_to_receive'] > 0) {
                $item = PurchaseOrderItem::where('id', $itemData['id'])
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->first();

                if ($item && $item->remaining_quantity > 0) {
                    $item->receive($itemData['quantity_to_receive']);
                    $receivedCount++;
                }
            }
        }

        if ($receivedCount === 0) {
            return response()->json([
                'message' => 'No items were received',
                'error' => 'no_items_received',
            ], 422);
        }

        return response()->json([
            'message' => 'Items received successfully',
            'data' => new PurchaseOrderResource($purchaseOrder->fresh()->load(['supplier', 'items.product'])),
        ]);
    }

    /**
     * Mark a purchase order as sent.
     */
    public function send(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (!$purchaseOrder->canBeSent()) {
            return response()->json([
                'message' => 'This purchase order cannot be sent',
                'error' => 'cannot_send',
            ], 422);
        }

        $purchaseOrder->markAsSent();

        return response()->json([
            'message' => 'Purchase order marked as sent',
            'data' => new PurchaseOrderResource($purchaseOrder),
        ]);
    }

    /**
     * Cancel a purchase order.
     */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Purchase order not found',
                'error' => 'not_found',
            ], 404);
        }

        if (!$purchaseOrder->canBeCancelled()) {
            return response()->json([
                'message' => 'This purchase order cannot be cancelled',
                'error' => 'cannot_cancel',
            ], 422);
        }

        $purchaseOrder->cancel();

        return response()->json([
            'message' => 'Purchase order cancelled',
            'data' => new PurchaseOrderResource($purchaseOrder),
        ]);
    }
}
