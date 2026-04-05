<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Dedoc\Scramble\Attributes\QueryParameter;

/**
 * @tags Warehouses
 */
class WarehouseController extends Controller
{
    /**
     * List warehouses.
     */
    #[QueryParameter('search', description: 'Search by name, code, or city', type: 'string')]
    #[QueryParameter('is_active', description: 'Filter by active status', type: 'boolean')]
    #[QueryParameter('sort_by', description: 'Sort field (default: created_at)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $query = Warehouse::forOrganization($organizationId)
            ->withCount(['locations', 'users'])
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('city', 'like', "%{$search}%");
                });
            })
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ['created_at', 'updated_at', 'name', 'code', 'city', 'is_active', 'priority'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'created_at';
        $sortDir = ($request->input('sort_dir') === 'asc') ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $warehouses = $query->paginate($perPage);

        return response()->json($warehouses);
    }

    /**
     * Store a newly created warehouse.
     *
     * @param Request $request The incoming HTTP request containing warehouse data
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where('organization_id', $organizationId),
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
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['organization_id'] = $organizationId;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // If this is the first warehouse for the org, set it as default
        $existingCount = Warehouse::forOrganization($organizationId)->count();
        if ($existingCount === 0) {
            $validated['is_default'] = true;
        }

        $warehouse = Warehouse::create($validated);
        $warehouse->loadCount(['locations', 'users']);

        return response()->json([
            'message' => 'Warehouse created successfully',
            'data' => $warehouse,
        ], 201);
    }

    /**
     * Display the specified warehouse.
     *
     * @param Request $request The incoming HTTP request
     * @param Warehouse $warehouse The warehouse to display
     * @return JsonResponse
     */
    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Warehouse not found',
                'error' => 'not_found',
            ], 404);
        }

        $warehouse->load(['locations', 'users:id,name,email']);
        $warehouse->loadCount(['locations', 'users']);

        return response()->json([
            'data' => $warehouse,
        ]);
    }

    /**
     * Update the specified warehouse.
     *
     * @param Request $request The incoming HTTP request containing updated warehouse data
     * @param Warehouse $warehouse The warehouse to update
     * @return JsonResponse
     */
    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Warehouse not found',
                'error' => 'not_found',
            ], 404);
        }

        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($warehouse->id),
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
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $warehouse->update($validated);
        $warehouse->loadCount(['locations', 'users']);

        return response()->json([
            'message' => 'Warehouse updated successfully',
            'data' => $warehouse,
        ]);
    }

    /**
     * Remove the specified warehouse.
     *
     * @param Request $request The incoming HTTP request
     * @param Warehouse $warehouse The warehouse to delete
     * @return JsonResponse
     */
    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($warehouse->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Warehouse not found',
                'error' => 'not_found',
            ], 404);
        }

        if ($warehouse->is_default) {
            return response()->json([
                'message' => 'Cannot delete the default warehouse. Set another warehouse as default first.',
                'error' => 'cannot_delete_default',
            ], 422);
        }

        if ($warehouse->locations()->whereHas('products')->exists()) {
            return response()->json([
                'message' => 'Cannot delete warehouse with locations that have associated products.',
                'error' => 'has_products',
            ], 422);
        }

        $warehouse->delete();

        return response()->json([
            'message' => 'Warehouse deleted successfully',
        ]);
    }
}
