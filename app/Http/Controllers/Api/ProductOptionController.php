<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductOptionResource;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * API Controller for managing product options.
 *
 * Handles RESTful API operations for product option CRUD operations
 * including reordering options.
 */
class ProductOptionController extends Controller
{
    /**
     * Display a listing of options for a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The product to list options for
     * @return AnonymousResourceCollection|JsonResponse
     */
    public function index(Request $request, Product $product): AnonymousResourceCollection|JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        $options = $product->options()->ordered()->get();

        return ProductOptionResource::collection($options);
    }

    /**
     * Store a newly created option.
     *
     * @param Request $request The incoming HTTP request containing option data
     * @param Product $product The product to create option for
     * @return JsonResponse
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        // Check max options limit (Shopify allows max 3)
        if ($product->options()->count() >= 3) {
            return response()->json([
                'message' => 'Maximum of 3 options allowed per product',
                'error' => 'max_options_exceeded',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'values' => ['required', 'array', 'min:1'],
            'values.*' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        // Check if option name already exists for this product
        if ($product->options()->where('name', $validated['name'])->exists()) {
            return response()->json([
                'message' => 'Option with this name already exists',
                'error' => 'duplicate_option',
            ], 422);
        }

        $validated['product_id'] = $product->id;
        $validated['position'] = $validated['position'] ?? $product->options()->count();

        $option = ProductOption::create($validated);

        return response()->json([
            'message' => 'Option created successfully',
            'data' => new ProductOptionResource($option),
        ], 201);
    }

    /**
     * Display the specified option.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product
     * @param ProductOption $option The option to display
     * @return JsonResponse
     */
    public function show(Request $request, Product $product, ProductOption $option): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($option->product_id !== $product->id) {
            return response()->json(['message' => 'Option not found', 'error' => 'not_found'], 404);
        }

        return response()->json([
            'data' => new ProductOptionResource($option),
        ]);
    }

    /**
     * Update the specified option.
     *
     * @param Request $request The incoming HTTP request containing updated option data
     * @param Product $product The parent product
     * @param ProductOption $option The option to update
     * @return JsonResponse
     */
    public function update(Request $request, Product $product, ProductOption $option): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($option->product_id !== $product->id) {
            return response()->json(['message' => 'Option not found', 'error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'values' => ['sometimes', 'array', 'min:1'],
            'values.*' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        // Check if new name conflicts with existing option
        if (isset($validated['name']) && $validated['name'] !== $option->name) {
            if ($product->options()->where('name', $validated['name'])->exists()) {
                return response()->json([
                    'message' => 'Option with this name already exists',
                    'error' => 'duplicate_option',
                ], 422);
            }
        }

        $option->update($validated);

        return response()->json([
            'message' => 'Option updated successfully',
            'data' => new ProductOptionResource($option),
        ]);
    }

    /**
     * Remove the specified option.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product
     * @param ProductOption $option The option to delete
     * @return JsonResponse
     */
    public function destroy(Request $request, Product $product, ProductOption $option): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($option->product_id !== $product->id) {
            return response()->json(['message' => 'Option not found', 'error' => 'not_found'], 404);
        }

        // Check if variants exist with this option
        $variantsUsingOption = $product->variants()
            ->get()
            ->filter(fn($v) => isset($v->option_values[$option->name]))
            ->count();

        if ($variantsUsingOption > 0) {
            return response()->json([
                'message' => "Cannot delete option: {$variantsUsingOption} variants are using this option",
                'error' => 'option_in_use',
            ], 422);
        }

        $option->delete();

        return response()->json([
            'message' => 'Option deleted successfully',
        ]);
    }

    /**
     * Reorder options for a product.
     *
     * @param Request $request The incoming HTTP request containing new order
     * @param Product $product The product to reorder options for
     * @return JsonResponse
     */
    public function reorder(Request $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'integer', 'exists:product_options,id'],
        ]);

        DB::transaction(function () use ($product, $validated) {
            foreach ($validated['order'] as $position => $optionId) {
                $product->options()->where('id', $optionId)->update(['position' => $position]);
            }
        });

        return response()->json([
            'message' => 'Options reordered successfully',
            'data' => ProductOptionResource::collection($product->options()->ordered()->get()),
        ]);
    }
}
