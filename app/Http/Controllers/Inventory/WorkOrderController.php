<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Enums\TrackingType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrder\StoreWorkOrderRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use App\Models\Warehouse;
use App\Services\WorkOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing assembly work orders.
 *
 * Handles CRUD operations and workflow transitions for work orders
 * including creating, starting, completing, and cancelling. Every stock
 * movement goes through WorkOrderService, shared with the REST API and MCP.
 */
class WorkOrderController extends Controller
{
    /**
     * How many available serials / batches the completion form lists per
     * component line.
     */
    private const PICKER_LIMIT = 500;

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
            'pluginComponents' => plugin_slots('work-orders.index', ['header', 'footer']),
            'workOrders' => $workOrders,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new work order.
     *
     * Each assembly carries its component lines per unit (virtual kits
     * exploded the way create() explodes them) and the variants the user can
     * pick for the output and for components stocked by variant.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request, WorkOrderService $workOrders): Response
    {
        $organizationId = $request->user()->organization_id;

        $assemblies = Product::forOrganization($organizationId)
            ->where('type', 'assembly')
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock', 'has_variants', 'tracking_type']);

        $assemblyProducts = $assemblies->map(function (Product $assembly) use ($workOrders) {
            try {
                $lines = $workOrders->componentLines($assembly);
            } catch (InvalidStateException) {
                $lines = [];
            }

            return [
                'id' => $assembly->id,
                'name' => $assembly->name,
                'sku' => $assembly->sku,
                'tracking_type' => $this->trackingValue($assembly),
                'variants' => $this->variantOptions($assembly),
                'components' => collect($lines)->values()->map(fn (array $line, int $index) => [
                    'key' => $index,
                    'product_id' => $line['product']->id,
                    'name' => $line['product']->name,
                    'sku' => $line['product']->sku,
                    'stock' => (int) $line['product']->stock,
                    'tracking_type' => $this->trackingValue($line['product']),
                    'quantity' => round($line['quantity'], 2),
                    'kit' => $line['kit_id'] === null ? null : Product::whereKey($line['kit_id'])->first(['id', 'name'])?->only(['id', 'name']),
                    'variants' => $this->variantOptions($line['product']),
                ])->all(),
            ];
        });

        return Inertia::render('WorkOrders/Create', [
            'assemblyProducts' => $assemblyProducts,
            'warehouses' => Warehouse::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'preselectedProductId' => $request->integer('product_id') ?: null,
        ]);
    }

    /**
     * Store a newly created work order.
     *
     * Auto-generates WO number and populates items from the assembly's BOM,
     * exploding virtual kits into their components.
     *
     * @param  Request  $request  The incoming HTTP request
     * @return RedirectResponse
     */
    public function store(StoreWorkOrderRequest $request, WorkOrderService $workOrders)
    {
        try {
            $workOrder = $workOrders->create($request->user(), $request->validated());
        } catch (InvalidStateException $e) {
            if (in_array($e->errorCode, ['invalid_product_type', 'no_components'], true)) {
                return redirect()->back()->withErrors(['product_id' => $e->getMessage()])->withInput();
            }

            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

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
            'product:id,name,sku,thumbnail,type,stock,tracking_type,has_variants',
            'variant:id,product_id,title,sku,stock',
            'creator:id,name',
            'warehouse:id,name',
            'items.product:id,name,sku,stock,thumbnail,tracking_type,type',
            'items.variant:id,product_id,title,sku,stock',
            'items.kit:id,name',
            'items.allocations.serial:id,serial_number',
            'items.allocations.batch:id,batch_number,expiry_date',
            'outputs.serial:id,serial_number',
            'outputs.batch:id,batch_number,expiry_date',
            'outputs.variant:id,title,sku',
        ]);

        $picking = $workOrder->status === 'in_progress';

        // Calculate component availability for each item, and for an order
        // in progress list the serials / batches the user can allocate.
        $items = $workOrder->items->map(function (WorkOrderItem $item) use ($picking) {
            $target = $item->variant ?? $item->product;
            $tracking = $item->variant === null && $item->product ? $this->trackingValue($item->product) : TrackingType::NONE->value;

            $item->setAttribute('tracking_type', $tracking);
            $item->setAttribute('available_stock', (int) ($target->stock ?? 0));
            $item->setAttribute('is_sufficient', ($target->stock ?? 0) >= ((float) $item->quantity_required - (float) $item->quantity_consumed));
            $item->setAttribute('available_serials', $picking && $tracking === TrackingType::SERIAL->value ? $this->availableSerials($item->product) : []);
            $item->setAttribute('available_batches', $picking && $tracking === TrackingType::BATCH->value ? $this->availableBatches($item->product) : []);

            return $item;
        });

        return Inertia::render('WorkOrders/Show', [
            'pluginComponents' => plugin_slots('work-orders.show', ['header', 'actions', 'footer']),
            'workOrder' => $workOrder,
            'items' => $items,
            'outputTrackingType' => $workOrder->product_variant_id === null && $workOrder->product
                ? $this->trackingValue($workOrder->product)
                : TrackingType::NONE->value,
            'allComponentsSufficient' => $items->every(fn ($item) => $item->is_sufficient),
        ]);
    }

    /**
     * Start a work order.
     *
     * Changes status from draft/pending to in_progress after verifying
     * all components have sufficient stock (and tracked units).
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to start
     * @return RedirectResponse
     */
    public function start(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeWorkOrder($request, $workOrder);

        try {
            app(WorkOrderService::class)->start($workOrder);
        } catch (BusinessRuleException $e) {
            return redirect()->route('work-orders.show', $workOrder)->with('error', $e->getMessage());
        }

        return redirect()->route('work-orders.show', $workOrder)
            ->with('success', 'Work order started successfully.');
    }

    /**
     * Complete a work order.
     *
     * Consumes the components (with the serials / batches the user chose, or
     * picked automatically) and receives the output (with its serials or
     * batch), all or nothing.
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

        $validated = $request->validate(WorkOrderService::completionRules($workOrder->quantity));

        try {
            $quantityProduced = app(WorkOrderService::class)->complete($workOrder, $validated);
        } catch (BusinessRuleException $e) {
            return redirect()->route('work-orders.show', $workOrder)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('work-orders.show', $workOrder)
            ->with('success', "Work order completed. {$quantityProduced} units produced.");
    }

    /**
     * Cancel a work order.
     *
     * If the work order recorded any consumption, returns exactly that.
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
            app(WorkOrderService::class)->cancel($workOrder);
        } catch (BusinessRuleException $e) {
            return redirect()->route('work-orders.show', $workOrder)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('work-orders.show', $workOrder)
            ->with('success', 'Work order has been cancelled.');
    }

    /**
     * Delete a work order.
     *
     * Only draft and cancelled work orders can be deleted: neither has moved
     * stock (a cancelled in-progress order has already had its consumed
     * components restored). Pending, in-progress and completed orders are
     * part of the stock history and must be cancelled instead.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  WorkOrder  $workOrder  The work order to delete
     * @return RedirectResponse
     */
    public function destroy(Request $request, WorkOrder $workOrder, WorkOrderService $workOrders)
    {
        $this->authorizeWorkOrder($request, $workOrder);

        try {
            $workOrders->delete($workOrder);
        } catch (InvalidStateException $e) {
            return redirect()->route('work-orders.show', $workOrder)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('work-orders.index')
            ->with('success', "Work order {$workOrder->work_order_number} deleted.");
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

    private function trackingValue(Product $product): string
    {
        $type = $product->tracking_type;

        return $type instanceof TrackingType ? $type->value : ($type ?: TrackingType::NONE->value);
    }

    /**
     * The variants a product is stocked by, or none when it is not stocked
     * by variant.
     *
     * @return list<array{id:int, title:string|null, sku:string|null, stock:int}>
     */
    private function variantOptions(Product $product): array
    {
        if (! $product->has_variants) {
            return [];
        }

        return ProductVariant::where('product_id', $product->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'title', 'sku', 'stock'])
            ->map(fn (ProductVariant $variant) => $variant->only(['id', 'title', 'sku', 'stock']))
            ->all();
    }

    /**
     * @return Collection<int, ProductSerial>
     */
    private function availableSerials(?Product $product): Collection
    {
        if ($product === null) {
            return collect();
        }

        return ProductSerial::where('product_id', $product->id)
            ->where('status', ProductSerial::STATUS_AVAILABLE)
            ->orderBy('id')
            ->limit(self::PICKER_LIMIT)
            ->get(['id', 'serial_number']);
    }

    /**
     * Batches in the order automatic allocation would use them.
     *
     * @return Collection<int, ProductBatch>
     */
    private function availableBatches(?Product $product): Collection
    {
        if ($product === null) {
            return collect();
        }

        return ProductBatch::where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->orderByRaw('expiry_date is null')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->limit(self::PICKER_LIMIT)
            ->get(['id', 'batch_number', 'quantity', 'expiry_date']);
    }
}
