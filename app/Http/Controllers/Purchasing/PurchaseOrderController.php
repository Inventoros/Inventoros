<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Exceptions\DocumentEmailException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrder\ProcessReceivingRequest;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Services\PurchaseOrderEmailService;
use App\Services\WarehouseAccessService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing purchase orders.
 *
 * Handles CRUD operations for purchase orders including creating,
 * editing, receiving, and managing purchase order lifecycle.
 */
class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of purchase orders.
     *
     * @param  Request  $request  The incoming HTTP request
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
     * Show the form for creating a new purchase order.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $suppliers = Supplier::forOrganization($organizationId)
            ->where('is_active', true)
            ->get(['id', 'name', 'currency', 'payment_terms']);

        $products = $this->formProducts($organizationId);

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
     * Store a newly created purchase order.
     *
     * @param  Request  $request  The incoming HTTP request containing purchase order data
     * @return RedirectResponse
     */
    public function store(StorePurchaseOrderRequest $request)
    {
        $validated = $request->validated();

        $organizationId = $request->user()->organization_id;

        // Verify supplier belongs to organization
        $supplier = Supplier::forOrganization($organizationId)->findOrFail($validated['supplier_id']);

        // Calculate order totals
        $subtotal = '0';
        $orderItems = [];

        foreach ($validated['items'] as $item) {
            $product = Product::forOrganization($organizationId)->findOrFail($item['product_id']);
            $variant = PurchaseOrderItem::resolveVariant($product, $item['product_variant_id'] ?? null);
            $itemSubtotal = Money::multiply($item['unit_cost'], $item['quantity']);
            $subtotal = Money::add($subtotal, $itemSubtotal);

            $orderItems[] = [
                'product_id' => $item['product_id'],
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'sku' => $variant?->sku ?? $product->sku,
                'supplier_sku' => $item['supplier_sku'] ?? null,
                'quantity_ordered' => $item['quantity'],
                'quantity_received' => 0,
                'unit_cost' => $item['unit_cost'],
                'subtotal' => $itemSubtotal,
                'tax' => 0,
                'total' => $itemSubtotal,
            ];
        }

        // Generate the PO number and insert the order + items atomically with a
        // retry, so a concurrent create in the same tenant/day can't collide on
        // po_number and 500.
        $purchaseOrder = PurchaseOrder::createWithNumber($organizationId, function (string $poNumber) use ($organizationId, $validated, $request, $subtotal, $orderItems) {
            $po = PurchaseOrder::create([
                'organization_id' => $organizationId,
                'supplier_id' => $validated['supplier_id'],
                'created_by' => $request->user()->id,
                'po_number' => $poNumber,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'subtotal' => $subtotal,
                'tax' => $validated['tax'] ?? 0,
                'shipping' => $validated['shipping'] ?? 0,
                'total' => Money::add($subtotal, $validated['tax'] ?? 0, $validated['shipping'] ?? 0),
                'currency' => $validated['currency'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $po->items()->createMany($orderItems);

            return $po;
        });

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order created successfully.');
    }

    /**
     * Display the specified purchase order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to display
     */
    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        // Ensure user can only view POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $purchaseOrder->load(['supplier', 'creator', 'items.product', 'items.variant']);

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
     * Show the form for editing the specified purchase order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to edit
     * @return Response|RedirectResponse
     */
    public function edit(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        // Ensure user can only edit POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow editing draft POs
        if (! $purchaseOrder->canBeEdited()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot be edited.');
        }

        $organizationId = $request->user()->organization_id;

        $suppliers = Supplier::forOrganization($organizationId)
            ->where('is_active', true)
            ->get(['id', 'name', 'currency', 'payment_terms']);

        $purchaseOrder->load('items.variant');

        $products = $this->formProducts(
            $organizationId,
            $purchaseOrder->items->pluck('product_variant_id')->filter()->all(),
        );

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
     * Update the specified purchase order.
     *
     * @param  Request  $request  The incoming HTTP request containing updated purchase order data
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to update
     * @return RedirectResponse
     */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        // Ensure user can only update POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow updating draft POs
        if (! $purchaseOrder->canBeEdited()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot be edited.');
        }

        $validated = $request->validated();

        $organizationId = $request->user()->organization_id;

        // Load existing items
        $purchaseOrder->load('items');
        $existingItems = $purchaseOrder->items->keyBy('id');

        // Track which items to keep
        $itemIdsToKeep = [];

        // Calculate new totals
        $subtotal = '0';
        $newItems = [];

        foreach ($validated['items'] as $itemData) {
            $product = Product::forOrganization($organizationId)->findOrFail($itemData['product_id']);
            $variant = PurchaseOrderItem::resolveVariant($product, $itemData['product_variant_id'] ?? null);
            $itemSubtotal = Money::multiply($itemData['unit_cost'], $itemData['quantity']);
            $subtotal = Money::add($subtotal, $itemSubtotal);

            if (! empty($itemData['id']) && $existingItems->has($itemData['id'])) {
                // Update existing item
                $existingItem = $existingItems->get($itemData['id']);
                $existingItem->update([
                    'product_id' => $itemData['product_id'],
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'sku' => $variant?->sku ?? $product->sku,
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
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'sku' => $variant?->sku ?? $product->sku,
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
            return ! in_array($item->id, $itemIdsToKeep);
        })->each->delete();

        // Create new items
        if (! empty($newItems)) {
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
            'total' => Money::add($subtotal, $validated['tax'] ?? 0, $validated['shipping'] ?? 0),
            'currency' => $validated['currency'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order updated successfully.');
    }

    /**
     * Remove the specified purchase order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to delete
     * @return RedirectResponse
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
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to receive items for
     * @return Response|RedirectResponse
     */
    public function receive(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        // Ensure user can only receive POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow receiving for sent/partial POs
        if (! $purchaseOrder->canReceiveItems()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot receive items.');
        }

        $purchaseOrder->load(['supplier', 'items.product', 'items.variant']);

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
     *
     * @param  Request  $request  The incoming HTTP request containing received quantities
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to process receiving for
     * @return RedirectResponse
     */
    public function processReceiving(ProcessReceivingRequest $request, PurchaseOrder $purchaseOrder)
    {
        // Ensure user can only receive POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Only allow receiving for sent/partial POs
        if (! $purchaseOrder->canReceiveItems()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot receive items.');
        }

        $validated = $request->validated();

        // Goods land in each product's primary location; a restricted user
        // can only book them into their own warehouses.
        app(WarehouseAccessService::class)->authorizeReceiving($request->user(), $purchaseOrder, $validated['items']);

        $receivedCount = 0;

        try {
            DB::transaction(function () use ($validated, $purchaseOrder, &$receivedCount) {
                // Re-read with a row lock so two concurrent "Receive" submits
                // serialize here; the second sees post-receive status/quantities.
                $po = PurchaseOrder::whereKey($purchaseOrder->getKey())->lockForUpdate()->firstOrFail();

                if (! $po->canReceiveItems()) {
                    throw new \RuntimeException('This purchase order cannot receive items.');
                }

                foreach ($validated['items'] as $itemData) {
                    if ($itemData['quantity_to_receive'] > 0) {
                        $item = PurchaseOrderItem::where('id', $itemData['id'])
                            ->where('purchase_order_id', $po->id)
                            ->lockForUpdate()
                            ->first();

                        if ($item && $item->remaining_quantity > 0) {
                            $item->receive($itemData['quantity_to_receive']);
                            $receivedCount++;
                        }
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', $e->getMessage());
        }

        if ($receivedCount > 0) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('success', 'Items received successfully.');
        }

        return redirect()->route('purchase-orders.receive', $purchaseOrder)
            ->with('error', 'No items were received.');
    }

    /**
     * Email a purchase order to its supplier (PDF attached) and mark it sent.
     *
     * Also used to re-send an order that is already with the supplier.
     *
     * @param  SendDocumentEmailRequest  $request  Optional recipient override, CC list and message
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to send
     * @return RedirectResponse
     */
    public function sendToSupplier(SendDocumentEmailRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderEmailService $emails)
    {
        // Ensure user can only send POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $purchaseOrder = $emails->send(
                $purchaseOrder,
                $request->user(),
                $request->recipient(),
                $request->ccList(),
                $request->customMessage(),
            );
        } catch (DocumentEmailException $e) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', "Purchase order emailed to {$purchaseOrder->sent_to}.");
    }

    /**
     * Cancel a purchase order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The purchase order to cancel
     * @return RedirectResponse
     */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Ensure user can only cancel POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if (! $purchaseOrder->canBeCancelled()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot be cancelled.');
        }

        $purchaseOrder->cancel();

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order cancelled.');
    }

    /**
     * The purchasable products for the PO form, each with its active variants
     * so a variant-tracked product can be bought variant by variant.
     *
     * @param  array<int, int>  $keepVariantIds  inactive variants to include anyway (lines already on the PO)
     * @return array<int, array<string, mixed>>
     */
    private function formProducts(int $organizationId, array $keepVariantIds = []): array
    {
        return Product::forOrganization($organizationId)
            ->active()
            ->with([
                'suppliers',
                'variants' => fn ($query) => $query->where(function ($q) use ($keepVariantIds) {
                    $q->where('is_active', true);
                    if ($keepVariantIds !== []) {
                        $q->orWhereIn('id', $keepVariantIds);
                    }
                }),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode', 'price', 'purchase_price', 'stock', 'has_variants', 'category_id', 'location_id'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'purchase_price' => $product->purchase_price,
                'stock' => (int) $product->stock,
                'suppliers' => $product->suppliers,
                'has_variants' => (bool) $product->has_variants,
                'variants' => $product->has_variants
                    ? $product->variants->map(fn (ProductVariant $variant) => [
                        'id' => $variant->id,
                        'title' => $variant->title,
                        'sku' => $variant->sku,
                        'barcode' => $variant->barcode,
                        'stock' => (int) $variant->stock,
                        'purchase_price' => $variant->purchase_price ?? $product->purchase_price,
                        'is_active' => (bool) $variant->is_active,
                    ])->values()->all()
                    : [],
            ])
            ->values()
            ->all();
    }
}
