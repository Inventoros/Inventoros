<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Warehouse;
use App\Services\OrderService;
use App\Support\Search;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing orders.
 *
 * Handles CRUD operations for orders including listing,
 * creating, updating, deleting, and order approval workflow.
 */
class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
    ) {}

    /**
     * Display a listing of orders.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;
        $canViewPayments = $request->user()->hasPermission(Permission::VIEW_PAYMENTS);

        $activeWarehouseId = session('active_warehouse_id');

        $orders = Order::with(['items', 'warehouse'])
            ->forOrganization($organizationId)
            ->when($activeWarehouseId, function ($query, $warehouseId) {
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
            // Filtering by payment status would reveal which orders are paid,
            // so it only applies for users who may see payments.
            ->when($canViewPayments && in_array($request->input('payment_status'), PaymentStatus::values(), true), function ($query) use ($request) {
                $query->where('payment_status', $request->input('payment_status'));
            })
            ->latest('order_date')
            ->paginate(config('limits.pagination.default'))
            ->withQueryString();

        // Serialize through the canonical API resource so the Inertia list and
        // the REST list expose an identical item shape (P2-15). through()
        // transforms each item while preserving the pagination envelope the
        // frontend pager depends on.
        $orders->through(fn (Order $order) => (new OrderResource($order))->resolve($request));

        $activeWarehouse = $activeWarehouseId
            ? Warehouse::find($activeWarehouseId)
            : null;

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only($canViewPayments ? ['search', 'status', 'source', 'payment_status'] : ['search', 'status', 'source']),
            'statuses' => ['pending', 'processing', 'shipped', 'delivered', 'cancelled'],
            'canViewPayments' => $canViewPayments,
            'paymentStatuses' => $canViewPayments ? PaymentStatus::values() : [],
            'sources' => ['manual', 'ebay', 'shopify', 'amazon'],
            'activeWarehouse' => $activeWarehouse,
            'pluginComponents' => [
                'header' => get_page_components('orders.index', 'header'),
                'beforeTable' => get_page_components('orders.index', 'before-table'),
                'footer' => get_page_components('orders.index', 'footer'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new order.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $products = $this->formProducts($organizationId);

        $warehouses = Warehouse::forOrganization($organizationId)
            ->active()
            ->get(['id', 'name', 'code', 'is_default']);

        $defaultWarehouseId = session('active_warehouse_id')
            ?? $warehouses->firstWhere('is_default', true)?->id;

        return Inertia::render('Orders/Create', [
            'products' => $products,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
        ]);
    }

    /**
     * Store a newly created order.
     *
     * @param  Request  $request  The incoming HTTP request containing order data
     * @return RedirectResponse
     */
    public function store(StoreOrderRequest $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validated();

        // Resolve warehouse: explicit > session > org default
        if (empty($validated['warehouse_id'])) {
            $validated['warehouse_id'] = session('active_warehouse_id')
                ?? Warehouse::forOrganization($organizationId)->where('is_default', true)->value('id');
        }

        // Order creation (lock → validate → decrement, wrapped in
        // SequenceNumberRetry) lives in OrderService so every surface — web,
        // REST, GraphQL, MCP — creates orders with identical invariants.
        try {
            $this->orderService->create($validated, $request->user());

            return redirect()->route('orders.index')
                ->with('success', 'Order created successfully.');
        } catch (ValidationException $e) {
            // Discount problems found while pricing the order are field
            // errors; let Laravel send them back to the form.
            throw $e;
        } catch (QueryException $e) {
            // Database errors carry SQL/table/column details in the
            // message that we don't want to render in an end-user flash
            // banner ("violates unique constraint
            // orders_organization_id_order_number_unique"). Log the full
            // detail for operators, surface a generic message to the user.
            Log::error('Order create failed with database error', [
                'organization_id' => $request->user()->organization_id,
                'user_id' => $request->user()->id,
                'sql_state' => $e->errorInfo[0] ?? null,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Could not save the order due to a database error. Please try again, or contact support if the problem persists.');
        } catch (\Exception $e) {
            // Business-rule exceptions thrown inside the transaction
            // (insufficient stock, unknown product, etc.) carry safe
            // messages we intentionally surface to the user.
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Display the specified order.
     *
     * @param  Order  $order  The order to display
     */
    public function show(Order $order): Response
    {
        $order->load(['items.product', 'organization', 'creator', 'approver']);

        // Ensure user can only view orders from their organization
        if ($order->organization_id !== auth()->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $canViewPayments = auth()->user()->hasPermission(Permission::VIEW_PAYMENTS);
        if ($canViewPayments) {
            $order->load(['payments' => fn ($query) => $query->with(['user', 'voider'])->orderBy('paid_at')->orderBy('id')]);
        }

        // Check if user can approve orders
        $canApprove = auth()->user()->hasPermission('approve_orders');

        return Inertia::render('Orders/Show', [
            // Canonical API resource shape, so the detail page and the REST
            // endpoint expose the same order shape (P2-15).
            'order' => (new OrderResource($order))->resolve(request()),
            'canApprove' => $canApprove,
            'canRecordPayments' => $canViewPayments && auth()->user()->hasPermission(Permission::RECORD_PAYMENTS),
            'paymentMethods' => $canViewPayments
                ? array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases())
                : [],
            'pluginComponents' => [
                'header' => get_page_components('orders.show', 'header'),
                'sidebar' => get_page_components('orders.show', 'sidebar'),
                'tabs' => get_page_components('orders.show', 'tabs'),
                'footer' => get_page_components('orders.show', 'footer'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Order  $order  The order to edit
     */
    public function edit(Request $request, Order $order): Response
    {
        // Ensure user can only edit orders from their organization
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $organizationId = $request->user()->organization_id;

        // Load each line's variant (so the form can show and round-trip it)
        // and the linked customer (so the customer picker starts on it).
        $order->load(['items.variant', 'customer']);

        // Keep the variants the order already uses selectable even if they
        // have since been deactivated, so editing the order doesn't force a
        // switch away from them.
        $products = $this->formProducts(
            $organizationId,
            $order->items->pluck('product_variant_id')->filter()->all(),
        );

        return Inertia::render('Orders/Edit', [
            'order' => $order,
            'products' => $products,
        ]);
    }

    /**
     * Update the specified order.
     *
     * @param  Request  $request  The incoming HTTP request containing updated order data
     * @param  Order  $order  The order to update
     * @return RedirectResponse
     */
    public function update(UpdateOrderRequest $request, Order $order)
    {
        // Ensure user can only update orders from their organization
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validated();

        try {
            DB::transaction(function () use ($validated, $order) {
                // Lock and re-read the order inside the transaction so a
                // double-submit or a concurrent ship/cancel can't be acted on
                // against a stale, pre-transaction model (e.g. restock twice).
                $order = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

                // A cancelled order's stock was already restored on cancel;
                // reactivating it would oversell (a live order with its
                // inventory handed back). Cancellation is terminal — create a
                // new order instead.
                if ($order->status === OrderStatus::CANCELLED
                    && isset($validated['status'])
                    && $validated['status'] !== OrderStatus::CANCELLED->value) {
                    throw new \RuntimeException('A cancelled order cannot be reactivated. Create a new order instead.');
                }

                $isCancelling = $validated['status'] === 'cancelled'
                    && $order->status !== OrderStatus::CANCELLED;

                if ($isCancelling) {
                    // Reject cancelling an order that already left the warehouse —
                    // restocking would lie about inventory that physically isn't
                    // here. Matches the REST/GraphQL guard. Thrown to the
                    // try/catch below, which flashes the error instead of 500ing.
                    if (in_array($order->status, [OrderStatus::SHIPPED, OrderStatus::DELIVERED], true)) {
                        throw new \RuntimeException(
                            "Cannot cancel an order that has already been {$order->status->value}."
                        );
                    }

                    // Release the order's stock (serials/batches/bins included)
                    // but keep the line items as a historical record.
                    $order->load('items.product', 'items.variant');
                    foreach ($order->items as $item) {
                        $this->orderService->restockItem($item, "Order {$order->order_number} cancelled", $order);
                    }

                } else {
                    // Any other edit replaces the lines wholesale through the
                    // audited fulfilment paths (bin consume + serial/batch
                    // allocation + variant-aware ledger), so quantity changes and
                    // added/removed lines cannot drift the per-location breakdown
                    // or the tracked records the way a hand-rolled per-line adjust
                    // did. InsufficientStock / InvalidOrderItem both extend
                    // RuntimeException and are flashed by the catch below.
                    $this->orderService->replaceItems(
                        $order,
                        $validated['items'],
                    );
                }

                // Recompute every total on the server from the stored lines
                // (discounts, tax, shipping). A bad discount, or a total cut
                // below what has already been paid, throws a
                // ValidationException that rolls the edit back and returns
                // the field errors to the form.
                $this->orderService->recalculateTotals(
                    $order,
                    $validated['discount_type'] ?? null,
                    $validated['discount_value'] ?? null,
                    $validated['tax'] ?? 0,
                    $validated['shipping'] ?? 0,
                );
                unset($validated['discount_type'], $validated['discount_value'], $validated['tax'], $validated['shipping']);

                // Update order timestamps based on status
                if ($validated['status'] === 'shipped' && ! $order->shipped_at) {
                    $validated['shipped_at'] = now();
                } elseif ($validated['status'] === 'delivered' && ! $order->delivered_at) {
                    $validated['delivered_at'] = now();
                }

                $order->update($validated);
            });
        } catch (\RuntimeException $e) {
            // Guard failures (e.g. cancelling a shipped order, insufficient
            // stock on an item increase) flash an error rather than 500ing.
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('orders.index')
            ->with('success', 'Order updated successfully.');
    }

    /**
     * Remove the specified order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Order  $order  The order to delete
     * @return RedirectResponse
     */
    public function destroy(Request $request, Order $order)
    {
        // Ensure user can only delete orders from their organization
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        DB::transaction(function () use ($order) {
            // Restock only when the units are still on hand and unreturned. The
            // service re-reads the locked status so a shipped/delivered order
            // (goods gone) or an already-cancelled order (already restocked)
            // isn't restocked into phantom inventory.
            $this->orderService->restockForDeletion($order);

            $order->delete();
        });

        return redirect()->route('orders.index')
            ->with('success', 'Order deleted successfully.');
    }

    /**
     * Approve an order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Order  $order  The order to approve
     * @return RedirectResponse
     */
    public function approve(Request $request, Order $order)
    {
        // Ensure user can only approve orders from their organization
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Check if order is pending approval
        if (! $order->isPendingApproval()) {
            return redirect()->back()->with('error', 'Order has already been processed.');
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->orderService->approve($order, $request->user(), $validated['notes'] ?? null);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Order approved successfully.');
    }

    /**
     * Reject an order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Order  $order  The order to reject
     * @return RedirectResponse
     */
    public function reject(Request $request, Order $order)
    {
        // Ensure user can only reject orders from their organization
        if ($order->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Check if order is pending approval
        if (! $order->isPendingApproval()) {
            return redirect()->back()->with('error', 'Order has already been processed.');
        }

        $validated = $request->validate([
            'notes' => 'required|string|max:500',
        ]);

        try {
            $this->orderService->reject($order, $request->user(), $validated['notes']);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Order rejected.');
    }

    /**
     * Search the organization's active customers for the order form's
     * customer picker. Returns the fields the form copies onto the order.
     */
    public function customerLookup(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q', ''));

        $customers = Customer::forOrganization($request->user()->organization_id)
            ->active()
            ->when($term !== '', fn ($query) => Search::apply($query, ['name', 'code', 'email', 'company_name', 'contact_name'], $term))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'company_name' => $customer->company_name,
                'email' => $customer->email,
                'shipping_address' => $customer->full_shipping_address,
                'billing_address' => $customer->full_billing_address,
            ]);

        return response()->json(['customers' => $customers->values()]);
    }

    /**
     * The sellable products for the order form, each with its active
     * variants so a variant-tracked product can be sold line by line.
     *
     * @param  array<int, int>  $keepVariantIds  inactive variants to include anyway (lines already on the order)
     * @return array<int, array<string, mixed>>
     */
    private function formProducts(int $organizationId, array $keepVariantIds = []): array
    {
        return Product::forOrganization($organizationId)
            ->active()
            ->with(['variants' => fn ($query) => $query->where(function ($q) use ($keepVariantIds) {
                $q->where('is_active', true);
                if ($keepVariantIds !== []) {
                    $q->orWhereIn('id', $keepVariantIds);
                }
            })])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'price', 'stock', 'has_variants', 'category_id', 'location_id'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => $product->price,
                'stock' => (int) $product->stock,
                'has_variants' => (bool) $product->has_variants,
                'variants' => $product->has_variants
                    ? $product->variants->map(fn (ProductVariant $variant) => [
                        'id' => $variant->id,
                        'title' => $variant->title,
                        'sku' => $variant->sku,
                        'barcode' => $variant->barcode,
                        'stock' => (int) $variant->stock,
                        'price' => $variant->price ?? $product->price,
                        'is_active' => (bool) $variant->is_active,
                    ])->values()->all()
                    : [],
            ])
            ->values()
            ->all();
    }
}
