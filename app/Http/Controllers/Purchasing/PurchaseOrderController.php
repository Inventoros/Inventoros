<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $purchaseOrders = PurchaseOrder::with(['supplier', 'creator'])
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
            })
            ->latest('order_date')
            ->paginate(15)
            ->withQueryString();

        $suppliers = Supplier::forOrganization($organizationId)
            ->where('is_active', true)
            ->get(['id', 'name']);

        return Inertia::render('PurchaseOrders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'suppliers' => $suppliers,
            'filters' => $request->only(['search', 'status', 'supplier_id']),
            'statuses' => [
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_PARTIAL,
                PurchaseOrder::STATUS_RECEIVED,
                PurchaseOrder::STATUS_CANCELLED,
            ],
            'pluginComponents' => [
                'header' => get_page_components('purchase-orders.index', 'header'),
                'beforeTable' => get_page_components('purchase-orders.index', 'before-table'),
                'footer' => get_page_components('purchase-orders.index', 'footer'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $suppliers = Supplier::forOrganization($organizationId)
            ->where('is_active', true)
            ->get(['id', 'name', 'currency', 'payment_terms']);

        $products = Product::forOrganization($organizationId)
            ->active()
            ->with(['category', 'location', 'suppliers'])
            ->get(['id', 'name', 'sku', 'price', 'purchase_price', 'stock', 'category_id', 'location_id']);

        return Inertia::render('PurchaseOrders/Create', [
            'suppliers' => $suppliers,
            'products' => $products,
            'pluginComponents' => [
                'header' => get_page_components('purchase-orders.create', 'header'),
                'footer' => get_page_components('purchase-orders.create', 'footer'),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'currency' => 'required|string|max:3',
            'shipping' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.supplier_sku' => 'nullable|string|max:255',
        ]);

        $organizationId = $request->user()->organization_id;

        // Verify supplier belongs to organization
        $supplier = Supplier::forOrganization($organizationId)->findOrFail($validated['supplier_id']);

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

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        // Ensure user can only view POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $purchaseOrder->load(['supplier', 'creator', 'items.product']);

        return Inertia::render('PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder,
            'pluginComponents' => [
                'header' => get_page_components('purchase-orders.show', 'header'),
                'sidebar' => get_page_components('purchase-orders.show', 'sidebar'),
                'footer' => get_page_components('purchase-orders.show', 'footer'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        // Ensure user can only edit POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow editing draft POs
        if (!$purchaseOrder->canBeEdited()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot be edited.');
        }

        $organizationId = $request->user()->organization_id;

        $suppliers = Supplier::forOrganization($organizationId)
            ->where('is_active', true)
            ->get(['id', 'name', 'currency', 'payment_terms']);

        $products = Product::forOrganization($organizationId)
            ->active()
            ->with(['category', 'location', 'suppliers'])
            ->get(['id', 'name', 'sku', 'price', 'purchase_price', 'stock', 'category_id', 'location_id']);

        $purchaseOrder->load('items');

        return Inertia::render('PurchaseOrders/Edit', [
            'purchaseOrder' => $purchaseOrder,
            'suppliers' => $suppliers,
            'products' => $products,
            'pluginComponents' => [
                'header' => get_page_components('purchase-orders.edit', 'header'),
                'footer' => get_page_components('purchase-orders.edit', 'footer'),
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Ensure user can only update POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow updating draft POs
        if (!$purchaseOrder->canBeEdited()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot be edited.');
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'currency' => 'required|string|max:3',
            'shipping' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:purchase_order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.supplier_sku' => 'nullable|string|max:255',
        ]);

        $organizationId = $request->user()->organization_id;

        // Load existing items
        $purchaseOrder->load('items');
        $existingItems = $purchaseOrder->items->keyBy('id');

        // Track which items to keep
        $itemIdsToKeep = [];

        // Calculate new totals
        $subtotal = 0;
        $newItems = [];

        foreach ($validated['items'] as $itemData) {
            $product = Product::forOrganization($organizationId)->findOrFail($itemData['product_id']);
            $itemSubtotal = $itemData['quantity'] * $itemData['unit_cost'];
            $subtotal += $itemSubtotal;

            if (!empty($itemData['id']) && $existingItems->has($itemData['id'])) {
                // Update existing item
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
                // New item
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

        // Delete removed items
        $existingItems->filter(function ($item) use ($itemIdsToKeep) {
            return !in_array($item->id, $itemIdsToKeep);
        })->each->delete();

        // Create new items
        if (!empty($newItems)) {
            $purchaseOrder->items()->createMany($newItems);
        }

        // Update purchase order
        $purchaseOrder->update([
            'supplier_id' => $validated['supplier_id'],
            'order_date' => $validated['order_date'],
            'expected_date' => $validated['expected_date'] ?? null,
            'subtotal' => $subtotal,
            'tax' => $validated['tax'] ?? 0,
            'shipping' => $validated['shipping'] ?? 0,
            'total' => $subtotal + ($validated['tax'] ?? 0) + ($validated['shipping'] ?? 0),
            'currency' => $validated['currency'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Ensure user can only delete POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow deleting draft POs
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return redirect()->route('purchase-orders.index')
                ->with('error', 'Only draft purchase orders can be deleted.');
        }

        $purchaseOrder->delete();

        return redirect()->route('purchase-orders.index')
            ->with('success', 'Purchase order deleted successfully.');
    }

    /**
     * Show the receiving form for a purchase order.
     */
    public function receive(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        // Ensure user can only receive POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow receiving for sent/partial POs
        if (!$purchaseOrder->canReceiveItems()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot receive items.');
        }

        $purchaseOrder->load(['supplier', 'items.product']);

        return Inertia::render('PurchaseOrders/Receive', [
            'purchaseOrder' => $purchaseOrder,
            'pluginComponents' => [
                'header' => get_page_components('purchase-orders.receive', 'header'),
                'footer' => get_page_components('purchase-orders.receive', 'footer'),
            ],
        ]);
    }

    /**
     * Process receiving items for a purchase order.
     */
    public function processReceiving(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Ensure user can only receive POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow receiving for sent/partial POs
        if (!$purchaseOrder->canReceiveItems()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot receive items.');
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity_to_receive' => 'required|integer|min:0',
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

        if ($receivedCount > 0) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('success', 'Items received successfully.');
        }

        return redirect()->route('purchase-orders.receive', $purchaseOrder)
            ->with('error', 'No items were received.');
    }

    /**
     * Mark a purchase order as sent to supplier.
     */
    public function sendToSupplier(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Ensure user can only send POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if (!$purchaseOrder->canBeSent()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot be sent.');
        }

        $purchaseOrder->markAsSent();

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order marked as sent.');
    }

    /**
     * Cancel a purchase order.
     */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Ensure user can only cancel POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if (!$purchaseOrder->canBeCancelled()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot be cancelled.');
        }

        $purchaseOrder->cancel();

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order cancelled.');
    }
}
