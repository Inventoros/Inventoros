<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockAudit\ListStockAuditItemsRequest;
use App\Http\Requests\StockAudit\ReviewStockAuditCountRequest;
use App\Http\Requests\StockAudit\StoreStockAuditRequest;
use App\Http\Requests\StockAudit\UpdateStockAuditRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditCount;
use App\Models\Inventory\StockAuditItem;
use App\Models\Inventory\StockAuditReview;
use App\Services\StockAuditReadService;
use App\Services\StockAuditReviewService;
use App\Services\StockAuditService;
use App\Services\WarehouseAccessService;
use App\Support\ApprovalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function reviewCount(ReviewStockAuditCountRequest $request, StockAudit $stockAudit, StockAuditItem $item, StockAuditReviewService $reviews): JsonResponse
    {
        $data = $request->validated();
        try {
            $reviews->decide($stockAudit, $item, $request->user(), $data['decision'], $data['expected_revision'], $data['notes'] ?? null);
        } catch (InvalidStateException $error) {
            return response()->json(['message' => $error->getMessage(), 'error' => $error->errorCode],
                in_array($error->errorCode, ['stale_count', 'review_already_decided'], true) ? 409 : 422);
        }

        return response()->json(['item' => $item->fresh(['product', 'countedByUser'])]);
    }

    public function countHistory(Request $request, StockAudit $stockAudit, StockAuditItem $item): Response
    {
        abort_unless((int) $stockAudit->organization_id === (int) $request->user()->organization_id, 403);
        $this->warehouseAccess->authorizeStockAudit($request->user(), $stockAudit);
        abort_unless((int) $item->stock_audit_id === (int) $stockAudit->id, 404);

        $counts = StockAuditCount::where('organization_id', $request->user()->organization_id)
            ->where('stock_audit_id', $stockAudit->id)->where('stock_audit_item_id', $item->id)
            ->orderByDesc('sequence')->paginate(20);

        return Inertia::render('StockAudits/CountHistory', [
            'audit' => $stockAudit->only(['id', 'audit_number', 'name']),
            'item' => $item->load(['product' => fn ($query) => $query->withTrashed()->where('organization_id', $stockAudit->organization_id),
                'variant' => fn ($query) => $query->withTrashed()->where('organization_id', $stockAudit->organization_id),
                'location' => fn ($query) => $query->withTrashed()->where('organization_id', $stockAudit->organization_id)]),
            'counts' => $counts,
            'reviews' => StockAuditReview::where('organization_id', $stockAudit->organization_id)
                ->where('stock_audit_id', $stockAudit->id)->where('stock_audit_item_id', $item->id)
                ->whereIn('count_sequence', $counts->pluck('sequence'))
                ->get()->mapWithKeys(fn ($review) => ['count-'.$review->count_sequence => $review]),
        ]);
    }

    /**
     * Display a listing of stock audits.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAudit::with(['warehouseLocation', 'warehouse', 'creator'])
            ->withCount('items')
            ->forOrganization($organizationId)
            ->tap(fn ($q) => $this->warehouseAccess->scopeStockAudits($q, $request->user()))
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
            'pluginComponents' => plugin_slots('stock-audits.index', ['header', 'footer']),
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
            ->get(['id', 'name', 'code', 'warehouse_id']);

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
    public function show(ListStockAuditItemsRequest $request, StockAudit $stockAudit, StockAuditReadService $read): Response
    {
        abort_unless((int) $stockAudit->organization_id === (int) $request->user()->organization_id, 403);
        $this->warehouseAccess->authorizeStockAudit($request->user(), $stockAudit);
        $stockAudit->load(['warehouseLocation', 'warehouse', 'creator']);
        $items = $read->paginate($stockAudit, $request->validated());
        $audit = $stockAudit->toArray();
        $audit['items'] = $items->getCollection()->map(fn ($item) => $read->present($item))->all();

        return Inertia::render('StockAudits/Show', [
            'pluginComponents' => plugin_slots('stock-audits.show', ['header', 'actions', 'footer']),
            'audit' => $audit, 'summary' => $read->summary($stockAudit), 'itemFilters' => $read->filters($request->validated()),
            'itemPagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total(),
                'per_page' => $items->perPage(), 'from' => $items->firstItem(), 'to' => $items->lastItem(),
                'next_page_url' => $items->nextPageUrl(), 'prev_page_url' => $items->previousPageUrl()],
            'scanLocations' => $read->locations($stockAudit),
            'canSelfApprove' => $request->user()->isAdmin() && ApprovalSettings::forOrganization((int) $stockAudit->organization_id)->adminsCanSelfApprove,
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

        $this->warehouseAccess->authorizeStockAudit($request->user(), $stockAudit);

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be edited.');
        }

        $organizationId = $request->user()->organization_id;

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->tap(fn ($q) => $this->warehouseAccess->scopeLocations($q, $request->user()))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'warehouse_id']);

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

        $this->warehouseAccess->authorizeStockAudit($request->user(), $stockAudit);

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
        if ($stockAudit->warehouse_id !== null) {
            if (($validated['warehouse_location_id'] ?? null) != $stockAudit->warehouse_location_id) {
                throw ValidationException::withMessages(['warehouse_location_id' => 'Create a new audit to change its warehouse count scope.']);
            }
        } else {
            $this->warehouseAccess->authorizeLocation($request->user(), $validated['warehouse_location_id'] ?? null);
        }

        DB::transaction(function () use ($stockAudit, $request, $validated): void {
            $locked = StockAudit::whereKey($stockAudit->id)->where('organization_id', $request->user()->organization_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['audit' => 'Only draft audits can be edited.']);
            }
            $this->warehouseAccess->authorizeStockAudit($request->user(), $locked);
            $locked->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'audit_type' => $validated['audit_type'],
                'warehouse_location_id' => $validated['warehouse_location_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'require_variance_approval' => (bool) ($validated['require_variance_approval'] ?? $locked->require_variance_approval),
            ]);
        }, 3);

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

        $this->warehouseAccess->authorizeStockAudit($request->user(), $stockAudit);

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

        $this->warehouseAccess->authorizeStockAudit($request->user(), $stockAudit);

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

        $this->warehouseAccess->authorizeStockAudit($request->user(), $stockAudit);

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
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->warehouseAccess->authorizeStockAudit($request->user(), $stockAudit);

        if ($stockAudit->status !== 'in_progress') {
            return response()->json(['message' => 'Audit is not in progress'], 422);
        }

        if ($item->stock_audit_id !== $stockAudit->id) {
            return response()->json(['message' => 'Item does not belong to this audit'], 422);
        }

        $validated = $request->validate([
            'counted_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
            'expected_revision' => ['sometimes', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);

        try {
            $audits->recordCount($stockAudit, $item, $request->user(), (int) $validated['counted_quantity'], $validated['notes'] ?? null, $validated['expected_revision'] ?? null);
        } catch (BusinessRuleException $e) {
            if ($e instanceof InvalidStateException && $e->errorCode === 'stale_count') {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'stale_count',
                    'item' => app(StockAuditReadService::class)->present($item->fresh()),
                    'summary' => app(StockAuditReadService::class)->summary($stockAudit),
                ], 409);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Count updated successfully',
            'item' => app(StockAuditReadService::class)->present($item->fresh()),
            'summary' => app(StockAuditReadService::class)->summary($stockAudit),
        ]);
    }
}
