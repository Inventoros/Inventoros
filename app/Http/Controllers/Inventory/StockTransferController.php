<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockTransfer\StoreStockTransferRequest;
use App\Http\Requests\StockTransfer\UpdateStockTransferRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockTransfer;
use App\Services\ApprovalService;
use App\Services\StockTransferService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing stock transfers between locations.
 *
 * Handles listing, creating, viewing, completing, and cancelling
 * stock transfers for inventory management.
 */
class StockTransferController extends Controller
{
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    /**
     * Display a listing of stock transfers.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;
        $activeWarehouseId = session('active_warehouse_id');

        $query = StockTransfer::with(['fromLocation', 'toLocation', 'fromWarehouse', 'toWarehouse', 'transferredBy', 'items'])
            ->forOrganization($organizationId)
            ->tap(fn ($q) => $this->warehouseAccess->scopeByAnyLocation($q, $request->user(), ['from_location_id', 'to_location_id']))
            ->when($activeWarehouseId, function ($query, $warehouseId) {
                $query->where(function ($q) use ($warehouseId) {
                    $q->where('from_warehouse_id', $warehouseId)
                        ->orWhere('to_warehouse_id', $warehouseId)
                        ->orWhereHas('fromLocation', function ($q2) use ($warehouseId) {
                            $q2->where('warehouse_id', $warehouseId);
                        })
                        ->orWhereHas('toLocation', function ($q2) use ($warehouseId) {
                            $q2->where('warehouse_id', $warehouseId);
                        });
                });
            })
            ->when($request->input('search'), function ($query, $search) {
                $query->where('transfer_number', 'like', "%{$search}%");
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->latest();

        $transfers = $query->paginate(20)->withQueryString();

        return Inertia::render('StockTransfers/Index', [
            'pluginComponents' => plugin_slots('stock-transfers.index', ['header', 'footer']),
            'transfers' => $transfers,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new stock transfer.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        // Any location can be a destination (sending stock to another
        // warehouse is allowed); the source must be one the user can access.
        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'warehouse_id']);

        $sourceLocationIds = $this->warehouseAccess->isRestricted($request->user())
            ? $locations->filter(fn ($location) => $this->warehouseAccess->canAccessLocation($request->user(), $location))->pluck('id')->values()
            : null;

        $products = Product::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock']);

        return Inertia::render('StockTransfers/Create', [
            'locations' => $locations,
            'sourceLocationIds' => $sourceLocationIds,
            'products' => $products,
        ]);
    }

    /**
     * Store a newly created stock transfer.
     *
     * @return RedirectResponse
     */
    public function store(StoreStockTransferRequest $request, StockTransferService $transfers)
    {
        // Stock can only leave a warehouse the user works in.
        $this->warehouseAccess->authorizeLocation($request->user(), (int) $request->validated()['from_location_id']);

        $transfer = $transfers->create($request->user()->organization_id, $request->user(), $request->validated());

        return redirect()->route('stock-transfers.show', $transfer)
            ->with('success', $transfer->approval_status === StockTransfer::APPROVAL_PENDING
                ? 'Stock transfer created. It needs approval before it can ship or be completed.'
                : 'Stock transfer created successfully.');
    }

    /**
     * Display the specified stock transfer.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockTransfer  $stockTransfer  The stock transfer to display
     */
    public function show(Request $request, StockTransfer $stockTransfer): Response
    {
        if ($stockTransfer->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeAnyLocation($request->user(), [$stockTransfer->from_location_id, $stockTransfer->to_location_id]);

        $stockTransfer->load(['fromLocation', 'toLocation', 'transferredBy', 'completer', 'items.product', 'approver']);

        return Inertia::render('StockTransfers/Show', [
            'pluginComponents' => plugin_slots('stock-transfers.show', ['header', 'actions', 'footer']),
            'transfer' => $stockTransfer,
            'approval' => [
                'can_decide' => $stockTransfer->approval_status === StockTransfer::APPROVAL_PENDING
                    && app(ApprovalService::class)->canDecide($request->user(), ApprovalService::STOCK_TRANSFER, $stockTransfer),
            ],
        ]);
    }

    /**
     * Update a stock transfer (e.g., status change to in_transit for inter-warehouse).
     *
     * @return RedirectResponse
     */
    public function update(UpdateStockTransferRequest $request, StockTransfer $stockTransfer, StockTransferService $transfers)
    {
        $this->authorizeTransfer($request, $stockTransfer);

        $validated = $request->validated();

        // Handle status change to in_transit
        if (isset($validated['status']) && $validated['status'] === 'in_transit') {
            return $this->transition(
                $stockTransfer,
                fn () => $transfers->ship($stockTransfer, $request->user(), $validated),
                'Stock transfer marked as in transit.',
            );
        }

        return redirect()->route('stock-transfers.show', $stockTransfer);
    }

    /**
     * Complete a stock transfer, adjusting stock levels.
     *
     * @return RedirectResponse
     */
    public function complete(Request $request, StockTransfer $stockTransfer, StockTransferService $transfers)
    {
        $this->authorizeTransfer($request, $stockTransfer);

        return $this->transition(
            $stockTransfer,
            fn () => $transfers->complete($stockTransfer, $request->user()),
            'Stock transfer completed.',
        );
    }

    /**
     * Cancel a stock transfer.
     *
     * @return RedirectResponse
     */
    public function cancel(Request $request, StockTransfer $stockTransfer, StockTransferService $transfers)
    {
        $this->authorizeTransfer($request, $stockTransfer);

        return $this->transition(
            $stockTransfer,
            fn () => $transfers->cancel($stockTransfer, $request->user()),
            'Stock transfer has been cancelled.',
        );
    }

    private function authorizeTransfer(Request $request, StockTransfer $stockTransfer): void
    {
        if ($stockTransfer->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeAnyLocation($request->user(), [$stockTransfer->from_location_id, $stockTransfer->to_location_id]);
    }

    /**
     * Run a lifecycle transition, flashing the service's refusal as an error.
     */
    private function transition(StockTransfer $stockTransfer, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (BusinessRuleException $e) {
            return redirect()->route('stock-transfers.show', $stockTransfer)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', $success);
    }
}
