<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Models\Inventory\Supplier;
use App\Support\PluginQueryGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing suppliers.
 *
 * Handles CRUD operations for suppliers including listing,
 * creating, updating, and deleting supplier records.
 * Supports plugin hooks for extensibility.
 */
class SupplierController extends Controller
{
    /**
     * Display a listing of suppliers.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $query = Supplier::withCount('products')
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->search($search);
            })
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->latest();

        // Hook: Modify supplier list query
        $query = apply_filters('supplier_list_query', $query, $request);

        // Re-assert tenant isolation: a plugin filter must not be able to widen
        // the query past the caller's organization (e.g. via withoutGlobalScope).
        $query = PluginQueryGuard::organizationScoped($query, Supplier::class, $organizationId);

        $suppliers = $query->paginate(15)->withQueryString();

        // Hook: Modify suppliers collection before rendering
        $suppliers = apply_filters('supplier_list_data', $suppliers, $request);

        $data = [
            'suppliers' => $suppliers,
            'filters' => $request->only(['search', 'is_active']),
            'pluginComponents' => [
                'header' => get_page_components('suppliers.index', 'header'),
                'beforeTable' => get_page_components('suppliers.index', 'before-table'),
                'footer' => get_page_components('suppliers.index', 'footer'),
            ],
        ];

        // Hook: Modify all data before rendering
        $data = apply_filters('supplier_list_page_data', $data, $request);

        // Action: Suppliers list viewed
        do_action('supplier_list_viewed', $suppliers, $request->user());

        return Inertia::render('Suppliers/Index', $data);
    }

    /**
     * Show the form for creating a new supplier.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Suppliers/Create', [
            'pluginComponents' => [
                'header' => get_page_components('suppliers.create', 'header'),
                'footer' => get_page_components('suppliers.create', 'footer'),
            ],
        ]);
    }

    /**
     * Store a newly created supplier.
     *
     * @param  Request  $request  The incoming HTTP request containing supplier data
     * @return RedirectResponse|JsonResponse
     */
    public function store(StoreSupplierRequest $request)
    {
        $validated = $request->validated();

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // Hook: Before supplier creation
        $validated = apply_filters('supplier_before_create', $validated, $request);

        $supplier = Supplier::create($validated);

        // Hook: After supplier creation
        do_action('supplier_created', $supplier, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Supplier created successfully.',
                'supplier' => $supplier,
            ], 201);
        }

        return redirect()->route('suppliers.index')
            ->with('success', 'Supplier created successfully.');
    }

    /**
     * Display the specified supplier.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Supplier  $supplier  The supplier to display
     */
    public function show(Request $request, Supplier $supplier): Response
    {
        // Ensure the supplier belongs to the user's organization
        if ($supplier->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $supplier->load('products');

        // Hook: Before showing supplier
        $supplier = apply_filters('supplier_before_show', $supplier, $request);

        // Action: Supplier viewed
        do_action('supplier_viewed', $supplier, $request->user());

        return Inertia::render('Suppliers/Show', [
            'supplier' => $supplier,
            'pluginComponents' => [
                'header' => get_page_components('suppliers.show', 'header'),
                'footer' => get_page_components('suppliers.show', 'footer'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified supplier.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Supplier  $supplier  The supplier to edit
     */
    public function edit(Request $request, Supplier $supplier): Response
    {
        // Ensure the supplier belongs to the user's organization
        if ($supplier->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        return Inertia::render('Suppliers/Edit', [
            'supplier' => $supplier,
            'pluginComponents' => [
                'header' => get_page_components('suppliers.edit', 'header'),
                'footer' => get_page_components('suppliers.edit', 'footer'),
            ],
        ]);
    }

    /**
     * Update the specified supplier.
     *
     * @param  Request  $request  The incoming HTTP request containing updated supplier data
     * @param  Supplier  $supplier  The supplier to update
     * @return RedirectResponse|JsonResponse
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        // Ensure the supplier belongs to the user's organization
        if ($supplier->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $validated = $request->validated();

        // Hook: Before supplier update
        $validated = apply_filters('supplier_before_update', $validated, $supplier, $request);

        $supplier->update($validated);

        // Hook: After supplier update
        do_action('supplier_updated', $supplier, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Supplier updated successfully.',
                'supplier' => $supplier,
            ]);
        }

        return redirect()->route('suppliers.index')
            ->with('success', 'Supplier updated successfully.');
    }

    /**
     * Remove the specified supplier.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Supplier  $supplier  The supplier to delete
     * @return RedirectResponse|JsonResponse
     */
    public function destroy(Request $request, Supplier $supplier)
    {
        // Ensure the supplier belongs to the user's organization
        if ($supplier->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        // Check if supplier has associated products
        if ($supplier->products()->count() > 0) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Cannot delete supplier with associated products.',
                ], 422);
            }

            return redirect()->route('suppliers.index')
                ->with('error', 'Cannot delete supplier with associated products.');
        }

        // Hook: Before supplier deletion
        do_action('supplier_before_delete', $supplier, $request->user());

        $supplier->delete();

        // Hook: After supplier deletion
        do_action('supplier_deleted', $supplier, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Supplier deleted successfully.',
            ]);
        }

        return redirect()->route('suppliers.index')
            ->with('success', 'Supplier deleted successfully.');
    }
}
