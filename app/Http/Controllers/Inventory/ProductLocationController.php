<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\ProductLocation;
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

        $locations = ProductLocation::forOrganization($organizationId)
            ->withCount('products')
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

        return Inertia::render('Locations/Index', [
            'locations' => $locations,
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
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

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
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
