<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Supplier;
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
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

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
     * @param Request $request The incoming HTTP request
     * @return Response
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
     * @param Request $request The incoming HTTP request containing supplier data
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:3'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

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
     * @param Request $request The incoming HTTP request
     * @param Supplier $supplier The supplier to display
     * @return Response
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
     * @param Request $request The incoming HTTP request
     * @param Supplier $supplier The supplier to edit
     * @return Response
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
     * @param Request $request The incoming HTTP request containing updated supplier data
     * @param Supplier $supplier The supplier to update
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Supplier $supplier)
    {
        // Ensure the supplier belongs to the user's organization
        if ($supplier->organization_id !== $request->user()->organization_id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:3'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

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
     * @param Request $request The incoming HTTP request
     * @param Supplier $supplier The supplier to delete
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
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
