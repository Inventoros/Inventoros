<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Inventory\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API Controller for managing products.
 *
 * Handles RESTful API operations for product CRUD operations.
 */
class ProductController extends Controller
{
    /**
     * Display a listing of products.
     *
     * @param Request $request The incoming HTTP request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $request->user()->organization_id;

        $query = Product::with(['category', 'location'])
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->input('category_id'), function ($query, $categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->when($request->input('location_id'), function ($query, $locationId) {
                $query->where('location_id', $locationId);
            })
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->input('low_stock'), function ($query) {
                $query->lowStock();
            });

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 100);
        $products = $query->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Store a newly created product.
     *
     * @param Request $request The incoming HTTP request containing product data
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'location_id' => ['nullable', 'integer', 'exists:product_locations,id'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['stock'] = $validated['stock'] ?? 0;

        $product = Product::create($validated);
        $product->load(['category', 'location']);

        return response()->json([
            'message' => 'Product created successfully',
            'data' => new ProductResource($product),
        ], 201);
    }

    /**
     * Display the specified product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The product to display
     * @return JsonResponse
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        // Ensure the product belongs to the user's organization
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Product not found',
                'error' => 'not_found',
            ], 404);
        }

        $product->load(['category', 'location']);

        return response()->json([
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Update the specified product.
     *
     * @param Request $request The incoming HTTP request containing updated product data
     * @param Product $product The product to update
     * @return JsonResponse
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        // Ensure the product belongs to the user's organization
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Product not found',
                'error' => 'not_found',
            ], 404);
        }

        $validated = $request->validate([
            'sku' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'location_id' => ['nullable', 'integer', 'exists:product_locations,id'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $product->update($validated);
        $product->load(['category', 'location']);

        return response()->json([
            'message' => 'Product updated successfully',
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Remove the specified product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The product to delete
     * @return JsonResponse
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        // Ensure the product belongs to the user's organization
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Product not found',
                'error' => 'not_found',
            ], 404);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }
}
