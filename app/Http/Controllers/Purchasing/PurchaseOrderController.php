<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Exceptions\ApprovalException;
use App\Exceptions\DocumentEmailException;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrder\ProcessReceivingRequest;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\ApprovalService;
use App\Services\PurchaseOrderEmailService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $purchaseOrders)
    {
        $purchaseOrder = $purchaseOrders->create($request->user()->organization_id, $request->user(), $request->validated());

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

        $purchaseOrder->load(['supplier', 'creator', 'items.product', 'items.variant', 'approver', 'approvalRequester']);

        return Inertia::render('PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder,
            'approval' => [
                'needs_approval' => $purchaseOrder->status === PurchaseOrder::STATUS_DRAFT && $purchaseOrder->needsApproval(),
                'can_submit' => $purchaseOrder->canBeSubmittedForApproval() && $request->user()->hasPermission('edit_purchase_orders'),
                'can_decide' => $purchaseOrder->approval_status === PurchaseOrder::APPROVAL_PENDING
                    && app(ApprovalService::class)->canDecide($request->user(), ApprovalService::PURCHASE_ORDER, $purchaseOrder),
            ],
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
    public function edit(Request $request, PurchaseOrder $purchaseOrder): Response|RedirectResponse
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
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrders)
    {
        // Ensure user can only update POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validated();

        // The web form always submits the full PO, so every header field is
        // written (blank tax/shipping mean zero, blank dates/notes mean null).
        try {
            $purchaseOrders->update($purchaseOrder, $request->user()->organization_id, [
                'supplier_id' => $validated['supplier_id'],
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'tax' => $validated['tax'] ?? 0,
                'shipping' => $validated['shipping'] ?? 0,
                'currency' => $validated['currency'],
                'notes' => $validated['notes'] ?? null,
                'items' => $validated['items'],
            ]);
        } catch (InvalidStateException) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot be edited.');
        }

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
    public function receive(Request $request, PurchaseOrder $purchaseOrder): Response|RedirectResponse
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
    public function processReceiving(ProcessReceivingRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrders)
    {
        // Ensure user can only receive POs from their organization
        if ($purchaseOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $receivedCount = $purchaseOrders->receive($purchaseOrder, $request->user(), $request->validated()['items']);
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
     * Submit a draft purchase order for approval.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  PurchaseOrder  $purchaseOrder  The draft to submit
     */
    public function submitForApproval(Request $request, PurchaseOrder $purchaseOrder, ApprovalService $approvals): RedirectResponse
    {
        try {
            $approvals->submitPurchaseOrder($purchaseOrder, $request->user());
        } catch (ApprovalException $e) {
            if ($e->reason === ApprovalException::NOT_FOUND) {
                abort(403, 'Unauthorized action.');
            }

            return redirect()->route('purchase-orders.show', $purchaseOrder)->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order submitted for approval.');
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
                // What each linked supplier charges for this product and
                // calls it, so a new PO line is priced at the chosen
                // supplier's cost rather than the generic purchase price.
                'supplier_costs' => $product->suppliers
                    ->map(fn ($supplier) => [
                        'supplier_id' => $supplier->id,
                        'cost_price' => $supplier->pivot->cost_price,
                        'supplier_sku' => $supplier->pivot->supplier_sku,
                    ])->values()->all(),
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
