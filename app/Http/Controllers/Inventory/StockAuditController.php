<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockAudit\StoreStockAuditRequest;
use App\Http\Requests\StockAudit\UpdateStockAuditRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Services\StockAuditService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing stock audits and cycle counting.
 *
 * Handles listing, creating, viewing, editing, starting, completing,
 * and counting stock audit records for inventory management.
 */
class StockAuditController extends Controller
{
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    /**
     * Display a listing of stock audits.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAudit::with(['warehouseLocation', 'creator'])
            ->withCount('items')
            ->forOrganization($organizationId)
            ->tap(fn ($q) => $this->warehouseAccess->scopeByLocation($q, $request->user(), 'warehouse_location_id'))
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('audit_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->input('audit_type'), function ($query, $type) {
                $query->where('audit_type', $type);
            })
            ->latest();

        $audits = $query->paginate(20)->withQueryString();

        return Inertia::render('StockAudits/Index', [
            'audits' => $audits,
            'filters' => $request->only(['search', 'status', 'audit_type']),
            'statuses' => [
                'draft' => 'Draft',
                'in_progress' => 'In Progress',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
            ],
            'auditTypes' => [
                'full' => 'Full Audit',
                'cycle' => 'Cycle Count',
                'spot' => 'Spot Check',
            ],
        ]);
    }

    /**
     * Show the form for creating a new stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->tap(fn ($q) => $this->warehouseAccess->scopeLocations($q, $request->user()))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $products = Product::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock']);

        return Inertia::render('StockAudits/Create', [
            'locations' => $locations,
            'products' => $products,
            'auditTypes' => [
                'full' => 'Full Audit',
                'cycle' => 'Cycle Count',
                'spot' => 'Spot Check',
            ],
        ]);
    }

    /**
     * Store a newly created stock audit.
     *
     * @return RedirectResponse
     */
    public function store(StoreStockAuditRequest $request, StockAuditService $audits)
    {
        // A restricted user audits their own warehouses only; an audit with no
        // location spans the whole organization.
        $this->warehouseAccess->authorizeLocation($request->user(), $request->validated()['warehouse_location_id'] ?? null);

        $audit = $audits->create($request->user()->organization_id, $request->user(), $request->validated());

        return redirect()->route('stock-audits.show', $audit)
            ->with('success', 'Stock audit created successfully with '.$audit->items()->count().' items.');
    }

    /**
     * Display the specified stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to display
     */
    public function show(Request $request, StockAudit $stockAudit): Response
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeLocation($request->user(), $stockAudit->warehouse_location_id);

        $stockAudit->load([
            'warehouseLocation',
            'creator',
            'items.product',
            'items.variant',
            'items.location',
            'items.countedByUser',
        ]);

        // Calculate summary stats
        $totalItems = $stockAudit->items->count();
        $countedItems = $stockAudit->items->where('status', '!=', 'pending')->count();
        $discrepancies = $stockAudit->items->where('counted_quantity', '!=', null)
            ->filter(fn ($item) => $item->counted_quantity !== $item->system_quantity)
            ->count();

        return Inertia::render('StockAudits/Show', [
            'audit' => $stockAudit,
            'summary' => [
                'total_items' => $totalItems,
                'counted_items' => $countedItems,
                'discrepancies' => $discrepancies,
                'progress' => $totalItems > 0 ? round(($countedItems / $totalItems) * 100) : 0,
            ],
        ]);
    }

    /**
     * Show the form for editing a stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to edit
     * @return Response
     */
    public function edit(Request $request, StockAudit $stockAudit): Response|RedirectResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeLocation($request->user(), $stockAudit->warehouse_location_id);

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be edited.');
        }

        $organizationId = $request->user()->organization_id;

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->tap(fn ($q) => $this->warehouseAccess->scopeLocations($q, $request->user()))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('StockAudits/Edit', [
            'audit' => $stockAudit,
            'locations' => $locations,
            'auditTypes' => [
                'full' => 'Full Audit',
                'cycle' => 'Cycle Count',
                'spot' => 'Spot Check',
            ],
        ]);
    }

    /**
     * Update the specified stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to update
     * @return RedirectResponse
     */
    public function update(UpdateStockAuditRequest $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeLocation($request->user(), $stockAudit->warehouse_location_id);

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be edited.');
        }

        $validated = $request->validated();

        $organizationId = $request->user()->organization_id;

        // Verify location belongs to organization if provided
        if (! empty($validated['warehouse_location_id'])) {
            ProductLocation::where('id', $validated['warehouse_location_id'])
                ->forOrganization($organizationId)
                ->firstOrFail();
        }

        // A restricted user audits their own warehouses only; an audit with no
        // location spans the whole organization.
        $this->warehouseAccess->authorizeLocation($request->user(), $validated['warehouse_location_id'] ?? null);

        $stockAudit->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'audit_type' => $validated['audit_type'],
            'warehouse_location_id' => $validated['warehouse_location_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('stock-audits.show', $stockAudit)
            ->with('success', 'Stock audit updated successfully.');
    }

    /**
     * Delete a stock audit (only if draft).
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to delete
     * @return RedirectResponse
     */
    public function destroy(Request $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeLocation($request->user(), $stockAudit->warehouse_location_id);

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be deleted.');
        }

        $stockAudit->delete();

        return redirect()->route('stock-audits.index')
            ->with('success', 'Stock audit deleted successfully.');
    }

    /**
     * Start a stock audit (transition from draft to in_progress).
     *
     * @return RedirectResponse
     */
    public function start(Request $request, StockAudit $stockAudit, StockAuditService $audits)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeLocation($request->user(), $stockAudit->warehouse_location_id);

        try {
            $audits->start($stockAudit, $request->user());
        } catch (BusinessRuleException $e) {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('stock-audits.show', $stockAudit)
            ->with('success', 'Stock audit started. System quantities have been recorded.');
    }

    /**
     * Complete a stock audit and create stock adjustments for discrepancies.
     *
     * @return RedirectResponse
     */
    public function complete(Request $request, StockAudit $stockAudit, StockAuditService $audits)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $this->warehouseAccess->authorizeLocation($request->user(), $stockAudit->warehouse_location_id);

        try {
            // allow_uncounted: the user confirmed completing with lines left
            // uncounted (they are left unchanged).
            $adjustmentsCreated = $audits->complete($stockAudit, $request->user(), $request->boolean('allow_uncounted'));
        } catch (BusinessRuleException $e) {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', $e->getMessage());
        }

        $message = 'Stock audit completed.';
        if ($adjustmentsCreated > 0) {
            $message .= " {$adjustmentsCreated} stock adjustment(s) created for discrepancies.";
        } else {
            $message .= ' No discrepancies found.';
        }

        return redirect()->route('stock-audits.show', $stockAudit)
            ->with('success', $message);
    }

    /**
     * Update the count for an individual audit item (AJAX endpoint).
     */
    public function updateCount(Request $request, StockAudit $stockAudit, StockAuditItem $item, StockAuditService $audits): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id
            || ! $this->warehouseAccess->canAccessLocation($request->user(), $stockAudit->warehouse_location_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($stockAudit->status !== 'in_progress') {
            return response()->json(['message' => 'Audit is not in progress'], 422);
        }

        if ($item->stock_audit_id !== $stockAudit->id) {
            return response()->json(['message' => 'Item does not belong to this audit'], 422);
        }

        $validated = $request->validate([
            'counted_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $audits->recordCount($stockAudit, $item, $request->user(), (int) $validated['counted_quantity'], $validated['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Count updated successfully',
            'item' => $item->fresh(['product', 'countedByUser']),
        ]);
    }
}
