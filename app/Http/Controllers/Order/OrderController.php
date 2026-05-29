<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Warehouse;
use App\Services\NotificationService;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
            ->latest('order_date')
            ->paginate(config('limits.pagination.default'))
            ->withQueryString();

        $activeWarehouse = $activeWarehouseId
            ? Warehouse::find($activeWarehouseId)
            : null;

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'source']),
            'statuses' => ['pending', 'processing', 'shipped', 'delivered', 'cancelled'],
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

        $products = Product::forOrganization($organizationId)
            ->active()
            ->with(['category', 'location'])
            ->get(['id', 'name', 'sku', 'price', 'stock', 'category_id', 'location_id']);

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

        // Check if user can approve orders
        $canApprove = auth()->user()->hasPermission('approve_orders');

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'canApprove' => $canApprove,
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

        $products = Product::forOrganization($organizationId)
            ->active()
            ->with(['category', 'location'])
            ->get(['id', 'name', 'sku', 'price', 'stock', 'category_id', 'location_id']);

        $order->load('items');

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

        DB::transaction(function () use ($validated, $order) {
            // Load existing items with product relationship
            $order->load('items.product');
            $existingItems = $order->items->keyBy('id');

            // Track which items to keep
            $itemIdsToKeep = [];

            // Calculate new totals
            $subtotal = 0;
            $updatedItems = [];

            foreach ($validated['items'] as $itemData) {
                $product = Product::where('id', $itemData['product_id'])->lockForUpdate()->first();
                $itemSubtotal = $itemData['quantity'] * $itemData['unit_price'];
                $subtotal += $itemSubtotal;

                if (! empty($itemData['id']) && $existingItems->has($itemData['id'])) {
                    // Update existing item
                    $existingItem = $existingItems->get($itemData['id']);
                    $quantityDiff = $itemData['quantity'] - $existingItem->quantity;

                    // Adjust stock based on quantity change
                    if ($quantityDiff > 0 && $product->stock < $quantityDiff) {
                        throw new \RuntimeException("Insufficient stock for {$product->name}. Available: {$product->stock}, requested increase: {$quantityDiff}");
                    }
                    if ($quantityDiff != 0) {
                        StockAdjustment::adjust(
                            $product,
                            -$quantityDiff,
                            $quantityDiff > 0 ? 'order_fulfillment' : 'order_cancellation',
                            "Order {$order->order_number} line updated",
                            null,
                            $order
                        );
                    }

                    $existingItem->update([
                        'product_id' => $itemData['product_id'],
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'subtotal' => $itemSubtotal,
                        'total' => $itemSubtotal,
                    ]);

                    $itemIdsToKeep[] = $itemData['id'];
                } else {
                    if ($product->stock < $itemData['quantity']) {
                        throw new \RuntimeException("Insufficient stock for {$product->name}. Available: {$product->stock}, requested: {$itemData['quantity']}");
                    }

                    // New item
                    $updatedItems[] = [
                        'product_id' => $itemData['product_id'],
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'subtotal' => $itemSubtotal,
                        'tax' => 0,
                        'total' => $itemSubtotal,
                    ];

                    // Reduce stock for new items via the ledger.
                    StockAdjustment::adjust(
                        $product,
                        -$itemData['quantity'],
                        'order_fulfillment',
                        "Order {$order->order_number} item added",
                        null,
                        $order
                    );
                }
            }

            // Delete removed items and restore their stock
            $itemsToDelete = $existingItems->filter(function ($item) use ($itemIdsToKeep) {
                return ! in_array($item->id, $itemIdsToKeep);
            });

            foreach ($itemsToDelete as $item) {
                if ($item->product) {
                    StockAdjustment::adjust(
                        $item->product,
                        $item->quantity,
                        'order_cancellation',
                        "Order {$order->order_number} item removed",
                        null,
                        $order
                    );
                }
                $item->delete();
            }

            // Create new items
            if (! empty($updatedItems)) {
                $order->items()->createMany($updatedItems);
            }

            // Restore stock when order is cancelled — route through
            // StockAdjustment so the cancellation appears in the ledger and
            // the row is locked correctly by adjust() itself.
            if ($validated['status'] === 'cancelled' && $order->status !== OrderStatus::CANCELLED) {
                $order->load('items.product');
                foreach ($order->items as $item) {
                    if ($item->product) {
                        StockAdjustment::adjust(
                            $item->product,
                            $item->quantity,
                            'order_cancellation',
                            "Order {$order->order_number} cancelled",
                            null,
                            $order
                        );
                    }
                }
            }

            // Update order totals and metadata
            $validated['subtotal'] = $subtotal;
            $validated['tax'] = $validated['tax'] ?? 0;
            $validated['shipping'] = $validated['shipping'] ?? 0;
            $validated['total'] = $subtotal + $validated['tax'] + $validated['shipping'];

            // Update order timestamps based on status
            if ($validated['status'] === 'shipped' && ! $order->shipped_at) {
                $validated['shipped_at'] = now();
            } elseif ($validated['status'] === 'delivered' && ! $order->delivered_at) {
                $validated['delivered_at'] = now();
            }

            $order->update($validated);
        });

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
            // Load items with product relationship for stock restoration
            $order->load('items.product');

            // Restore stock for all items via StockAdjustment so the
            // deletion is reflected in the ledger.
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

        $order->update([
            'approval_status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_notes' => $validated['notes'] ?? null,
        ]);

        // Load the approver relationship for notification
        $order->load('approver');

        // Send notification to order creator
        NotificationService::createOrderApprovalNotification($order);

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

        DB::transaction(function () use ($order, $request, $validated) {
            // Stock was decremented when the order was created. Rejection has
            // to restore it through the ledger so the inventory count and
            // audit trail line up with what's physically available — without
            // this the rejected order holds phantom reserved stock forever
            // and the reorder logic over-purchases.
            $order->load('items.product');
            foreach ($order->items as $item) {
                if ($item->product) {
                    StockAdjustment::adjust(
                        $item->product,
                        $item->quantity,
                        'order_cancellation',
                        "Order {$order->order_number} rejected",
                        null,
                        $order
                    );
                }
            }

            $order->update([
                'approval_status' => 'rejected',
                'status' => 'cancelled',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_notes' => $validated['notes'],
            ]);
        });

        // Load the approver relationship for notification
        $order->load('approver');

        // Send notification to order creator
        NotificationService::createOrderApprovalNotification($order);

        return redirect()->back()->with('success', 'Order rejected.');
    }
}
