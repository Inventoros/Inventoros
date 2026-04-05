<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @tags Product Components
 */
class ProductComponentController extends Controller
{
    /**
     * List components for a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product (kit or assembly)
     * @return JsonResponse
     */
    public function index(Request $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Product not found',
                'error' => 'not_found',
            ], 404);
        }

        if (!in_array($product->type, ['kit', 'assembly'])) {
            return response()->json([
                'message' => 'Only kit and assembly products can have components.',
                'error' => 'invalid_product_type',
            ], 422);
        }

        $components = $product->components()
            ->with('componentProduct:id,name,sku,stock,price,thumbnail')
            ->ordered()
            ->get();

        return response()->json([
            'data' => $components,
        ]);
    }

    /**
     * Add a component to a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product (kit or assembly)
     * @return JsonResponse
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Product not found',
                'error' => 'not_found',
            ], 404);
        }

        if (!in_array($product->type, ['kit', 'assembly'])) {
            return response()->json([
                'message' => 'Only kit and assembly products can have components.',
                'error' => 'invalid_product_type',
            ], 422);
        }

        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'component_product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('organization_id', $organizationId),
                function ($attribute, $value, $fail) use ($product) {
                    if ((int) $value === $product->id) {
                        $fail('A product cannot be a component of itself.');
                    }
                },
                Rule::unique('product_components')
                    ->where('parent_product_id', $product->id),
            ],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Check for circular reference
        if ($this->wouldCreateCircularReference($product->id, (int) $validated['component_product_id'])) {
            return response()->json([
                'message' => 'Adding this component would create a circular reference.',
                'error' => 'circular_reference',
            ], 422);
        }

        $maxSortOrder = $product->components()->max('sort_order') ?? -1;

        $component = ProductComponent::create([
            'parent_product_id' => $product->id,
            'component_product_id' => $validated['component_product_id'],
            'quantity' => $validated['quantity'],
            'sort_order' => $maxSortOrder + 1,
            'notes' => $validated['notes'] ?? null,
        ]);

        $component->load('componentProduct:id,name,sku,stock,price,thumbnail');

        return response()->json([
            'message' => 'Component added successfully',
            'data' => $component,
        ], 201);
    }

    /**
     * Update a component's quantity or notes.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product (kit or assembly)
     * @param ProductComponent $component The component to update
     * @return JsonResponse
     */
    public function update(Request $request, Product $product, ProductComponent $component): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Product not found',
                'error' => 'not_found',
            ], 404);
        }

        if ($component->parent_product_id !== $product->id) {
            return response()->json([
                'message' => 'Component not found for this product',
                'error' => 'not_found',
            ], 404);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $component->update($validated);
        $component->load('componentProduct:id,name,sku,stock,price,thumbnail');

        return response()->json([
            'message' => 'Component updated successfully',
            'data' => $component,
        ]);
    }

    /**
     * Remove a component from a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product (kit or assembly)
     * @param ProductComponent $component The component to remove
     * @return JsonResponse
     */
    public function destroy(Request $request, Product $product, ProductComponent $component): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json([
                'message' => 'Product not found',
                'error' => 'not_found',
            ], 404);
        }

        if ($component->parent_product_id !== $product->id) {
            return response()->json([
                'message' => 'Component not found for this product',
                'error' => 'not_found',
            ], 404);
        }

        $component->delete();

        return response()->json([
            'message' => 'Component removed successfully',
        ]);
    }

    /**
     * Check if adding a component would create a circular reference.
     *
     * @param int $parentId The parent product ID
     * @param int $componentId The component product ID to add
     * @param array $visited Previously visited product IDs (for recursion)
     * @return bool
     */
    private function wouldCreateCircularReference(int $parentId, int $componentId, array $visited = []): bool
    {
        $componentProduct = Product::find($componentId);

        if (!$componentProduct || !in_array($componentProduct->type, ['kit', 'assembly'])) {
            return false;
        }

        $visited[] = $parentId;

        $subComponents = ProductComponent::where('parent_product_id', $componentId)->get();

        foreach ($subComponents as $subComponent) {
            if ($subComponent->component_product_id === $parentId) {
                return true;
            }

            if (in_array($subComponent->component_product_id, $visited)) {
                return true;
            }

            if ($this->wouldCreateCircularReference($parentId, $subComponent->component_product_id, $visited)) {
                return true;
            }
        }

        return false;
    }
}
