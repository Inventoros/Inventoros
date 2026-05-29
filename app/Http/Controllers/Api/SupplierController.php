<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Supplier\StoreSupplierRequest;
use App\Http\Requests\Api\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Inventory\Supplier;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Suppliers
 */
class SupplierController extends Controller
{
    /**
     * List suppliers.
     */
    #[QueryParameter('search', description: 'Search by name, code, contact name, or email', type: 'string')]
    #[QueryParameter('is_active', description: 'Filter by active status', type: 'boolean')]
    #[QueryParameter('sort_by', description: 'Sort field (default: created_at)', type: 'string')]
    #[QueryParameter('sort_dir', description: 'Sort direction: asc or desc (default: desc)', type: 'string', enum: ['asc', 'desc'])]
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = Supplier::withCount('products')
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->search($search);
            })
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        // Sorting (allowlist to prevent SQL injection)
        $allowedSortColumns = ['created_at', 'updated_at', 'name', 'email', 'phone'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'created_at';
        $sortDir = ($request->input('sort_dir') === 'asc') ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $suppliers = $query->paginate($perPage);

        return SupplierResource::collection($suppliers);
    }

    /**
     * Store a newly created supplier.
     *
     * @param  Request  $request  The incoming HTTP request containing supplier data
     */
    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $supplier = Supplier::create($validated);

        return response()->json([
            'message' => 'Supplier created successfully',
            'data' => new SupplierResource($supplier),
        ], 201);
    }

    /**
     * Display the specified supplier.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Supplier  $supplier  The supplier to display
     */
    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        if ($supplier->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Supplier not found',
                'error' => 'not_found',
            ], 404);
        }

        $supplier->loadCount('products');
        $supplier->load('products');

        return response()->json([
            'data' => new SupplierResource($supplier),
        ]);
    }

    /**
     * Update the specified supplier.
     *
     * @param  Request  $request  The incoming HTTP request containing updated supplier data
     * @param  Supplier  $supplier  The supplier to update
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        if ($supplier->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Supplier not found',
                'error' => 'not_found',
            ], 404);
        }

        $validated = $request->validated();

        $supplier->update($validated);

        return response()->json([
            'message' => 'Supplier updated successfully',
            'data' => new SupplierResource($supplier),
        ]);
    }

    /**
     * Remove the specified supplier.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Supplier  $supplier  The supplier to delete
     */
    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        if ($supplier->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Supplier not found',
                'error' => 'not_found',
            ], 404);
        }

        // Check if supplier has products
        if ($supplier->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete supplier with associated products',
                'error' => 'has_products',
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted successfully',
        ]);
    }
}
