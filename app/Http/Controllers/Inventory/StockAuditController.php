<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    /**
     * Display a listing of stock audits.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAudit::with(['warehouseLocation', 'creator'])
            ->withCount('items')
            ->forOrganization($organizationId)
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
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
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
     * @param Request $request The incoming HTTP request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audit_type' => 'required|in:full,cycle,spot',
            'warehouse_location_id' => 'nullable|exists:product_locations,id',
            'notes' => 'nullable|string|max:2000',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        $organizationId = $request->user()->organization_id;

        // Verify location belongs to organization if provided
        if (!empty($validated['warehouse_location_id'])) {
            ProductLocation::where('id', $validated['warehouse_location_id'])
                ->forOrganization($organizationId)
                ->firstOrFail();
        }

        $audit = DB::transaction(function () use ($validated, $organizationId, $request) {
            $audit = StockAudit::create([
                'organization_id' => $organizationId,
                'audit_number' => StockAudit::generateAuditNumber($organizationId),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => 'draft',
                'audit_type' => $validated['audit_type'],
                'warehouse_location_id' => $validated['warehouse_location_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Get products to include in the audit
            $productQuery = Product::forOrganization($organizationId)->active();

            if (!empty($validated['product_ids'])) {
                // Specific products selected
                $productQuery->whereIn('id', $validated['product_ids']);
            } elseif (!empty($validated['warehouse_location_id'])) {
                // Filter by location
                $productQuery->where('location_id', $validated['warehouse_location_id']);
            }

            $products = $productQuery->get();

            foreach ($products as $product) {
                StockAuditItem::create([
                    'stock_audit_id' => $audit->id,
                    'product_id' => $product->id,
                    'location_id' => $product->location_id,
                    'system_quantity' => $product->stock,
                    'status' => 'pending',
                ]);
            }

            return $audit;
        });

        return redirect()->route('stock-audits.show', $audit)
            ->with('success', 'Stock audit created successfully with ' . $audit->items()->count() . ' items.');
    }

    /**
     * Display the specified stock audit.
     *
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit to display
     * @return Response
     */
    public function show(Request $request, StockAudit $stockAudit): Response
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

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
            ->filter(fn($item) => $item->counted_quantity !== $item->system_quantity)
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
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit to edit
     * @return Response
     */
    public function edit(Request $request, StockAudit $stockAudit): Response|\Illuminate\Http\RedirectResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be edited.');
        }

        $organizationId = $request->user()->organization_id;

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
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
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit to update
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be edited.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audit_type' => 'required|in:full,cycle,spot',
            'warehouse_location_id' => 'nullable|exists:product_locations,id',
            'notes' => 'nullable|string|max:2000',
        ]);

        $organizationId = $request->user()->organization_id;

        // Verify location belongs to organization if provided
        if (!empty($validated['warehouse_location_id'])) {
            ProductLocation::where('id', $validated['warehouse_location_id'])
                ->forOrganization($organizationId)
                ->firstOrFail();
        }

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
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit to delete
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

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
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit to start
     * @return \Illuminate\Http\RedirectResponse
     */
    public function start(Request $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be started.');
        }

        if ($stockAudit->items()->count() === 0) {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Cannot start an audit with no items.');
        }

        // Refresh system quantities from current stock levels
        DB::transaction(function () use ($stockAudit) {
            foreach ($stockAudit->items as $item) {
                $currentStock = $item->product->stock;
                $item->update(['system_quantity' => $currentStock]);
            }

            $stockAudit->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
        });

        return redirect()->route('stock-audits.show', $stockAudit)
            ->with('success', 'Stock audit started. System quantities have been recorded.');
    }

    /**
     * Complete a stock audit and create stock adjustments for discrepancies.
     *
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit to complete
     * @return \Illuminate\Http\RedirectResponse
     */
    public function complete(Request $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'in_progress') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only in-progress audits can be completed.');
        }

        $adjustmentsCreated = 0;

        DB::transaction(function () use ($stockAudit, &$adjustmentsCreated) {
            $stockAudit->load('items.product');

            foreach ($stockAudit->items as $item) {
                // Skip items that haven't been counted
                if ($item->counted_quantity === null) {
                    continue;
                }

                $discrepancy = $item->counted_quantity - $item->system_quantity;

                // Update the discrepancy field
                $item->update([
                    'discrepancy' => $discrepancy,
                    'status' => 'adjusted',
                ]);

                // Create stock adjustment if there's a discrepancy
                if ($discrepancy !== 0) {
                    StockAdjustment::adjust(
                        product: $item->product,
                        quantity: $discrepancy,
                        type: 'recount',
                        reason: "Stock audit: {$stockAudit->audit_number}",
                        notes: "Audit '{$stockAudit->name}' - System: {$item->system_quantity}, Counted: {$item->counted_quantity}",
                        reference: $stockAudit,
                    );

                    $adjustmentsCreated++;
                }
            }

            $stockAudit->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        });

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
     *
     * @param Request $request The incoming HTTP request
     * @param StockAudit $stockAudit The stock audit
     * @param StockAuditItem $item The audit item to update
     * @return JsonResponse
     */
    public function updateCount(Request $request, StockAudit $stockAudit, StockAuditItem $item): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
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

        $discrepancy = $validated['counted_quantity'] - $item->system_quantity;

        $item->update([
            'counted_quantity' => $validated['counted_quantity'],
            'discrepancy' => $discrepancy,
            'status' => 'counted',
            'counted_by' => $request->user()->id,
            'counted_at' => now(),
            'notes' => $validated['notes'] ?? $item->notes,
        ]);

        return response()->json([
            'message' => 'Count updated successfully',
            'item' => $item->fresh(['product', 'countedByUser']),
        ]);
    }
}
