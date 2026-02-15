<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCategoryResource;
use App\Models\Inventory\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API Controller for managing product categories.
 *
 * Handles RESTful API operations for product category CRUD operations.
 */
class ProductCategoryController extends Controller
{
    /**
     * Display a listing of categories.
     *
     * @param Request $request The incoming HTTP request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = ProductCategory::withCount('products')
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->input('root_only'), function ($query) {
                $query->root();
            });

        // Sorting
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $categories = $query->paginate($perPage);

        return ProductCategoryResource::collection($categories);
    }

    /**
     * Store a newly created category.
     *
     * @param Request $request The incoming HTTP request containing category data
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $category = ProductCategory::create($validated);

        return response()->json([
            'message' => 'Category created successfully',
            'data' => new ProductCategoryResource($category),
        ], 201);
    }

    /**
     * Display the specified category.
     *
     * @param Request $request The incoming HTTP request
     * @param ProductCategory $category The category to display
     * @return JsonResponse
     */
    public function show(Request $request, ProductCategory $category): JsonResponse
    {
        if ($category->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Category not found',
                'error' => 'not_found',
            ], 404);
        }

        $category->loadCount('products');
        $category->load(['parent', 'children']);

        return response()->json([
            'data' => new ProductCategoryResource($category),
        ]);
    }

    /**
     * Update the specified category.
     *
     * @param Request $request The incoming HTTP request containing updated category data
     * @param ProductCategory $category The category to update
     * @return JsonResponse
     */
    public function update(Request $request, ProductCategory $category): JsonResponse
    {
        if ($category->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Category not found',
                'error' => 'not_found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => new ProductCategoryResource($category),
        ]);
    }

    /**
     * Remove the specified category.
     *
     * @param Request $request The incoming HTTP request
     * @param ProductCategory $category The category to delete
     * @return JsonResponse
     */
    public function destroy(Request $request, ProductCategory $category): JsonResponse
    {
        if ($category->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Category not found',
                'error' => 'not_found',
            ], 404);
        }

        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with associated products',
                'error' => 'has_products',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }
}
