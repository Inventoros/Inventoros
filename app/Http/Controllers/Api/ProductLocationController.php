<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductLocationResource;
use App\Models\Inventory\ProductLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductLocationController extends Controller
{
    /**
     * Display a listing of locations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = ProductLocation::withCount('products')
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        // Sorting
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $locations = $query->paginate($perPage);

        return ProductLocationResource::collection($locations);
    }

    /**
     * Store a newly created location.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'aisle' => ['nullable', 'string', 'max:255'],
            'shelf' => ['nullable', 'string', 'max:255'],
            'bin' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $location = ProductLocation::create($validated);

        return response()->json([
            'message' => 'Location created successfully',
            'data' => new ProductLocationResource($location),
        ], 201);
    }

    /**
     * Display the specified location.
     */
    public function show(Request $request, ProductLocation $location): JsonResponse
    {
        if ($location->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Location not found',
                'error' => 'not_found',
            ], 404);
        }

        $location->loadCount('products');

        return response()->json([
            'data' => new ProductLocationResource($location),
        ]);
    }

    /**
     * Update the specified location.
     */
    public function update(Request $request, ProductLocation $location): JsonResponse
    {
        if ($location->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Location not found',
                'error' => 'not_found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'aisle' => ['nullable', 'string', 'max:255'],
            'shelf' => ['nullable', 'string', 'max:255'],
            'bin' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $location->update($validated);

        return response()->json([
            'message' => 'Location updated successfully',
            'data' => new ProductLocationResource($location),
        ]);
    }

    /**
     * Remove the specified location.
     */
    public function destroy(Request $request, ProductLocation $location): JsonResponse
    {
        if ($location->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Location not found',
                'error' => 'not_found',
            ], 404);
        }

        // Check if location has products
        if ($location->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete location with associated products',
                'error' => 'has_products',
            ], 422);
        }

        $location->delete();

        return response()->json([
            'message' => 'Location deleted successfully',
        ]);
    }
}
