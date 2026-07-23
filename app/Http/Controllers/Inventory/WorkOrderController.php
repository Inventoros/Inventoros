<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrder\StoreWorkOrderRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing assembly work orders.
 *
 * Handles CRUD operations and workflow transitions for work orders
 * including creating, starting, completing, and cancelling.
 */
class WorkOrderController extends Controller
{
    /**
     * Display a listing of work orders.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;
        $activeWarehouseId = session('active_warehouse_id');

        $query = WorkOrder::with(['product:id,name,sku,thumbnail', 'creator:id,name'])
            ->forOrganization($organizationId)
            ->when($activeWarehouseId, function ($query, $warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            })
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
                $query->where('status', $status);
            })
            ->latest();

        $workOrders = $query->paginate(20)->withQueryString();

        return Inertia::render('WorkOrders/Index', [
            'workOrders' => $workOrders,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new work order.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $assemblyProducts = Product::forOrganization($organizationId)
            ->where('type', 'assembly')
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);

        return Inertia::render('WorkOrders/Create', [
            'assemblyProducts' => $assemblyProducts,
        ]);
    }

    /**
     * Store a newly created work order.
     *
     * Auto-generates WO number and populates items from assembly's BOM.
     *
     * @param  Request  $request  The incoming HTTP request
     * @return RedirectResponse
     */
    public function store(StoreWorkOrderRequest $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validated();

        // Verify the product is an assembly type
        $product = Product::where('id', $validated['product_id'])
            ->forOrganization($organizationId)
            ->firstOrFail();

        if ($product->type !== 'assembly') {
            return redirect()->back()
                ->withErrors(['product_id' => 'Work orders can only be created for assembly products.'])
                ->withInput();
        }

        // Load the product's components
        $components = $product->components()->with('componentProduct')->get();

        if ($components->isEmpty()) {
            return redirect()->back()
                ->withErrors(['product_id' => 'This assembly has no components defined. Add components before creating a work order.'])
                ->withInput();
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

        return redirect()->route('work-orders.show', $workOrder)
            ->with('success', 'Work order created successfully.');
    }

    /**
     * Display the specified work order.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to display
     */
    public function show(Request $request, WorkOrder $workOrder): Response
    {
        $this->authorizeWorkOrder($request, $workOrder);

        $workOrder->load([
            'product:id,name,sku,thumbnail,type',
            'creator:id,name',
            'items.product:id,name,sku,stock,thumbnail',
        ]);

        // Calculate component availability for each item
        $items = $workOrder->items->map(function ($item) {
            $item->available_stock = $item->product->stock ?? 0;
            $item->is_sufficient = $item->available_stock >= ($item->quantity_required - $item->quantity_consumed);

            return $item;
        });

        return Inertia::render('WorkOrders/Show', [
            'workOrder' => $workOrder,
            'items' => $items,
            'allComponentsSufficient' => $items->every(fn ($item) => $item->is_sufficient),
        ]);
    }

    /**
     * Start a work order.
     *
     * Changes status from draft/pending to in_progress after verifying
     * all components have sufficient stock.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to start
     * @return RedirectResponse
     */
    public function start(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($request, $workOrder);

        if (! in_array($workOrder->status, ['draft', 'pending'])) {
            return redirect()->route('work-orders.show', $workOrder)
                ->with('error', 'Only draft or pending work orders can be started.');
        }

        // Check component stock availability
        $workOrder->load('items.product');

        foreach ($workOrder->items as $item) {
            $remaining = $item->quantity_required - $item->quantity_consumed;
            if ($item->product->stock < $remaining) {
                return redirect()->route('work-orders.show', $workOrder)
                    ->with('error', "Insufficient stock for component '{$item->product->name}'. Available: {$item->product->stock}, Required: {$remaining}.");
            }
        }

        $workOrder->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return redirect()->route('work-orders.show', $workOrder)
            ->with('success', 'Work order started successfully.');
    }

    /**
     * Complete a work order.
     *
     * Decrements component stock and increments assembly product stock.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to complete
     * @return RedirectResponse
     */
    public function complete(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($request, $workOrder);

        if ($workOrder->status !== 'in_progress') {
            return redirect()->route('work-orders.show', $workOrder)
                ->with('error', 'Only in-progress work orders can be completed.');
        }

        $validated = $request->validate([
            'quantity_produced' => 'nullable|integer|min:1|max:'.$workOrder->quantity,
        ]);

        $quantityProduced = $validated['quantity_produced'] ?? $workOrder->quantity;

        try {
            DB::transaction(function () use ($workOrder, $quantityProduced) {
                // Lock and re-read the row so a concurrent complete cannot pass
                // the pre-transaction guard and double-consume/double-produce
                // stock. Re-assert the status guard against the locked instance.
                $workOrder = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

                if ($workOrder->status !== 'in_progress') {
                    throw new \RuntimeException('Only in-progress work orders can be completed.');
                }

                $workOrder->load('items.product');

                // Decrement component stock
                foreach ($workOrder->items as $item) {
                    $consumeQty = $item->quantity_required - $item->quantity_consumed;
                    $consumeQtyInt = (int) ceil($consumeQty);

                    if ($consumeQtyInt > 0) {
                        // Re-validate availability under the lock and refuse to
                        // drive a component negative: stock may have been drained
                        // (by sales, other WOs) while this WO sat in progress.
                        StockAdjustment::adjust(
                            $item->product,
                            -$consumeQtyInt,
                            'assembly_consumption',
                            "Consumed for work order {$workOrder->work_order_number}",
                            "Assembly of {$workOrder->product->name}",
                            $workOrder,
                            allowNegative: false
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
        } catch (\RuntimeException $e) {
            return redirect()->route('work-orders.show', $workOrder)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('work-orders.show', $workOrder)
            ->with('success', "Work order completed. {$quantityProduced} units produced.");
    }

    /**
     * Cancel a work order.
     *
     * If the work order was in progress, restores any consumed component stock.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to cancel
     * @return RedirectResponse
     */
    public function cancel(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($request, $workOrder);

        if (! in_array($workOrder->status, ['draft', 'pending', 'in_progress'])) {
            return redirect()->route('work-orders.show', $workOrder)
                ->with('error', 'Only draft, pending, or in-progress work orders can be cancelled.');
        }

        try {
            DB::transaction(function () use ($workOrder) {
                // Lock and re-read the row so a concurrent cancel (or a cancel
                // racing a complete) cannot pass the pre-transaction guard and
                // double-restore consumed stock. Re-assert the guard against the
                // locked instance.
                $workOrder = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

                if (! in_array($workOrder->status, ['draft', 'pending', 'in_progress'], true)) {
                    throw new \RuntimeException('Only draft, pending, or in-progress work orders can be cancelled.');
                }

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
                                'Work order cancelled - restoring consumed stock',
                                $workOrder
                            );

                            $item->update(['quantity_consumed' => 0]);
                        }
                    }
                }

                $workOrder->update(['status' => 'cancelled']);
            });
        } catch (\RuntimeException $e) {
            return redirect()->route('work-orders.show', $workOrder)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('work-orders.show', $workOrder)
            ->with('success', 'Work order has been cancelled.');
    }

    /**
     * Authorize that the work order belongs to the user's organization.
     */
    private function authorizeWorkOrder(Request $request, WorkOrder $workOrder): void
    {
        if ($workOrder->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }
    }
}
