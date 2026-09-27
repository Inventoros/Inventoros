<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProductLocation\StoreProductLocationRequest;
use App\Http\Requests\Api\ProductLocation\UpdateProductLocationRequest;
use App\Http\Resources\ProductLocationResource;
use App\Models\Inventory\ProductLocation;
use App\Services\WarehouseAccessService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Locations
 */
class ProductLocationController extends Controller
{
    /**
     * List locations.
     */
    #[QueryParameter('search', description: 'Search by name or code', type: 'string')]
    #[QueryParameter('is_active', description: 'Filter by active status', type: 'boolean')]
    #[QueryParameter('sort_by', description: 'Sort field (default: name)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: asc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = ProductLocation::withCount('products')
            ->forOrganization($organizationId)
            ->tap(fn ($q) => app(WarehouseAccessService::class)->scopeLocations($q, $request->user()))
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ['created_at', 'updated_at', 'name'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'name';
        $sortDir = ($request->input('sort_dir') === 'desc') ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $locations = $query->paginate($perPage);

        return ProductLocationResource::collection($locations);
    }

    /**
     * Store a newly created location.
     *
     * @param  Request  $request  The incoming HTTP request containing location data
     */
    public function store(StoreProductLocationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        app(WarehouseAccessService::class)->authorizeWarehouse($request->user(), isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null);

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
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  ProductLocation  $location  The location to display
     */
    public function show(Request $request, ProductLocation $location): JsonResponse
    {
        if ($location->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Location not found',
                'error' => 'not_found',
            ], 404);
        }

        app(WarehouseAccessService::class)->authorizeLocation($request->user(), $location);

        $location->loadCount('products');

        return response()->json([
            'data' => new ProductLocationResource($location),
        ]);
    }

    /**
     * Update the specified location.
     *
     * @param  Request  $request  The incoming HTTP request containing updated location data
     * @param  ProductLocation  $location  The location to update
     */
    public function update(UpdateProductLocationRequest $request, ProductLocation $location): JsonResponse
    {
        if ($location->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Location not found',
                'error' => 'not_found',
            ], 404);
        }

        $access = app(WarehouseAccessService::class);
        $access->authorizeLocation($request->user(), $location);

        $validated = $request->validated();

        if (array_key_exists('warehouse_id', $validated)) {
            $access->authorizeWarehouse($request->user(), $validated['warehouse_id'] !== null ? (int) $validated['warehouse_id'] : null);
        }

        $location->update($validated);

        return response()->json([
            'message' => 'Location updated successfully',
            'data' => new ProductLocationResource($location),
        ]);
    }

    /**
     * Remove the specified location.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  ProductLocation  $location  The location to delete
     */
    public function destroy(Request $request, ProductLocation $location): JsonResponse
    {
        if ($location->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Location not found',
                'error' => 'not_found',
            ], 404);
        }

        app(WarehouseAccessService::class)->authorizeLocation($request->user(), $location);

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
