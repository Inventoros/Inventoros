<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing warehouses.
 *
 * Handles CRUD operations for warehouses including user assignment,
 * default warehouse management, and active warehouse switching.
 */
class WarehouseController extends Controller
{
    /**
     * Display a listing of warehouses.
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $warehouses = Warehouse::forOrganization($organizationId)
            ->withCount(['locations', 'users'])
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('city', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Warehouses/Index', [
            'warehouses' => $warehouses,
            'filters' => [
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    /**
     * Show the form for creating a new warehouse.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Warehouses/Create');
    }

    /**
     * Store a newly created warehouse.
     */
    public function store(Request $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate($this->validationRules($organizationId));

        $validated['organization_id'] = $organizationId;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // If this is the first warehouse for the org, set it as default
        $existingCount = Warehouse::forOrganization($organizationId)->count();
        if ($existingCount === 0) {
            $validated['is_default'] = true;
        }

        $warehouse = Warehouse::create($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'warehouse' => $warehouse,
                'message' => 'Warehouse created successfully.',
            ]);
        }

        return redirect()->route('warehouses.show', $warehouse)
            ->with('success', 'Warehouse created successfully.');
    }

    /**
     * Display the specified warehouse with its locations and assigned users.
     */
    public function show(Request $request, Warehouse $warehouse): Response
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $warehouse->load(['locations', 'users']);
        $warehouse->loadCount(['locations', 'users']);

        return Inertia::render('Warehouses/Show', [
            'warehouse' => $warehouse,
        ]);
    }

    /**
     * Show the form for editing the specified warehouse.
     */
    public function edit(Request $request, Warehouse $warehouse): Response
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $warehouse->load(['users']);

        // Get all org users for assignment dropdown
        $orgUsers = User::where('organization_id', $request->user()->organization_id)
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Warehouses/Edit', [
            'warehouse' => $warehouse,
            'orgUsers' => $orgUsers,
        ]);
    }

    /**
     * Update the specified warehouse.
     */
    public function update(Request $request, Warehouse $warehouse)
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $organizationId = $request->user()->organization_id;

        $validated = $request->validate($this->validationRules($organizationId, $warehouse->id));

        $warehouse->update($validated);

        return redirect()->route('warehouses.show', $warehouse)
            ->with('success', 'Warehouse updated successfully.');
    }

    /**
     * Remove the specified warehouse.
     */
    public function destroy(Request $request, Warehouse $warehouse)
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($warehouse->is_default) {
            return redirect()->back()
                ->withErrors(['warehouse' => 'Cannot delete the default warehouse. Set another warehouse as default first.']);
        }

        // Check if warehouse has locations with products
        if ($warehouse->locations()->whereHas('products')->exists()) {
            return redirect()->back()
                ->withErrors(['warehouse' => 'Cannot delete warehouse with locations that have associated products.']);
        }

        $warehouse->delete();

        return redirect()->route('warehouses.index')
            ->with('success', 'Warehouse deleted successfully.');
    }

    /**
     * Sync user assignments to the warehouse.
     */
    public function updateUsers(Request $request, Warehouse $warehouse)
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('organization_id', $request->user()->organization_id)],
        ]);

        $warehouse->users()->sync($validated['user_ids']);

        return redirect()->back()
            ->with('success', 'Warehouse user assignments updated successfully.');
    }

    /**
     * Set the specified warehouse as the organization default.
     */
    public function setDefault(Request $request, Warehouse $warehouse)
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $organizationId = $request->user()->organization_id;

        // Remove default from all other warehouses in this org
        Warehouse::forOrganization($organizationId)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $warehouse->update(['is_default' => true]);

        return redirect()->back()
            ->with('success', 'Default warehouse updated successfully.');
    }

    /**
     * Set the user's active warehouse in session.
     */
    public function setActiveWarehouse(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        $warehouseId = $validated['warehouse_id'] ?? null;

        if ($warehouseId !== null) {
            // Verify user has access to this warehouse
            if (!$request->user()->hasWarehouseAccess($warehouseId)) {
                abort(403, 'You do not have access to this warehouse.');
            }

            // Verify warehouse belongs to user's org
            $exists = Warehouse::forOrganization($request->user()->organization_id)
                ->where('id', $warehouseId)
                ->active()
                ->exists();

            if (!$exists) {
                abort(404, 'Warehouse not found.');
            }
        }

        session(['active_warehouse_id' => $warehouseId]);

        return redirect()->back()
            ->with('success', $warehouseId ? 'Active warehouse changed.' : 'Viewing all warehouses.');
    }

    /**
     * Get validation rules for store/update.
     */
    private function validationRules(int $organizationId, ?int $excludeId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($excludeId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'max:3'],
            'is_active' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
