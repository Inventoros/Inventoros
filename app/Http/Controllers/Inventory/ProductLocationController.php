<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\ProductLocation;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing product locations.
 *
 * Handles CRUD operations for product storage locations
 * with plugin integration support.
 */
class ProductLocationController extends Controller
{
    /**
     * Display a listing of locations.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;
        $activeWarehouseId = session('active_warehouse_id');

        $locations = ProductLocation::forOrganization($organizationId)
            ->with('warehouse:id,name,code')
            ->withCount('products')
            ->when($activeWarehouseId, function ($query, $warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            })
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn($location) => $location);

        $warehouses = Warehouse::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('Locations/Index', [
            'locations' => $locations,
            'warehouses' => $warehouses,
            'activeWarehouseId' => $activeWarehouseId,
            'filters' => [
                'search' => $request->input('search', ''),
            ],
            'pluginComponents' => [
                'header' => get_page_components('locations.index', 'header'),
                'footer' => get_page_components('locations.index', 'footer'),
            ],
        ]);
    }

    /**
     * Store a newly created location.
     *
     * @param Request $request The incoming HTTP request containing location data
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'warehouse_id' => [
                'nullable',
                'exists:warehouses,id',
                function ($attribute, $value, $fail) use ($organizationId) {
                    if ($value && !Warehouse::where('id', $value)->where('organization_id', $organizationId)->exists()) {
                        $fail('The selected warehouse does not belong to your organization.');
                    }
                },
            ],
        ]);

        $validated['organization_id'] = $organizationId;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $location = ProductLocation::create($validated);

        // If this is an AJAX request (from inline form), return JSON
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'location' => $location,
                'message' => 'Location created successfully.',
            ]);
        }

        return redirect()->route('locations.index')
            ->with('success', 'Location created successfully.');
    }

    /**
     * Update the specified location.
     *
     * @param Request $request The incoming HTTP request containing updated location data
     * @param ProductLocation $location The location to update
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, ProductLocation $location)
    {
        // Ensure user can only update locations from their organization
        if ($location->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'warehouse_id' => [
                'nullable',
                'exists:warehouses,id',
                function ($attribute, $value, $fail) use ($organizationId) {
                    if ($value && !Warehouse::where('id', $value)->where('organization_id', $organizationId)->exists()) {
                        $fail('The selected warehouse does not belong to your organization.');
                    }
                },
            ],
        ]);

        $location->update($validated);

        return redirect()->route('locations.index')
            ->with('success', 'Location updated successfully.');
    }

    /**
     * Remove the specified location.
     *
     * @param Request $request The incoming HTTP request
     * @param ProductLocation $location The location to delete
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, ProductLocation $location)
    {
        // Ensure user can only delete locations from their organization
        if ($location->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        // Check if location has products
        if ($location->products()->count() > 0) {
            return redirect()->back()
                ->withErrors(['location' => 'Cannot delete location with associated products.']);
        }

        $location->delete();

        return redirect()->route('locations.index')
            ->with('success', 'Location deleted successfully.');
    }
}
