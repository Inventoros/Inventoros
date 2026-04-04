<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Controller for managing product components (Bill of Materials).
 *
 * Handles CRUD operations for components of kit and assembly products,
 * including adding, updating, removing, and reordering components.
 */
class ProductComponentController extends Controller
{
    /**
     * Display a listing of components for a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product (kit or assembly)
     * @return JsonResponse
     */
    public function index(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $components = $product->components()
            ->with('componentProduct:id,name,sku,stock,price,thumbnail')
            ->ordered()
            ->get();

        return response()->json([
            'components' => $components,
        ]);
    }

    /**
     * Store a new component for a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product (kit or assembly)
     * @return JsonResponse
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'component_product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('organization_id', $organizationId),
                function ($attribute, $value, $fail) use ($product) {
                    // Prevent self-referencing
                    if ((int) $value === $product->id) {
                        $fail('A product cannot be a component of itself.');
                    }
                },
                Rule::unique('product_components')
                    ->where('parent_product_id', $product->id),
            ],
            'quantity' => 'required|numeric|min:0.01|max:999999.99',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Check for circular reference
        if ($this->wouldCreateCircularReference($product->id, (int) $validated['component_product_id'])) {
            return response()->json([
                'message' => 'Adding this component would create a circular reference.',
                'errors' => ['component_product_id' => ['Adding this component would create a circular reference.']],
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
            'message' => 'Component added successfully.',
            'component' => $component,
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
        $this->authorizeProduct($request, $product);
        $this->authorizeComponent($product, $component);

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01|max:999999.99',
            'notes' => 'nullable|string|max:1000',
        ]);

        $component->update($validated);
        $component->load('componentProduct:id,name,sku,stock,price,thumbnail');

        return response()->json([
            'message' => 'Component updated successfully.',
            'component' => $component,
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
        $this->authorizeProduct($request, $product);
        $this->authorizeComponent($product, $component);

        $component->delete();

        return response()->json([
            'message' => 'Component removed successfully.',
        ]);
    }

    /**
     * Reorder components for a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product (kit or assembly)
     * @return JsonResponse
     */
    public function reorder(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => [
                'required',
                'integer',
                Rule::exists('product_components', 'id')
                    ->where('parent_product_id', $product->id),
            ],
            'items.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['items'] as $item) {
            ProductComponent::where('id', $item['id'])
                ->where('parent_product_id', $product->id)
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json([
            'message' => 'Components reordered successfully.',
        ]);
    }

    /**
     * Authorize that the product belongs to the user's organization
     * and is a kit or assembly type.
     *
     * @param Request $request
     * @param Product $product
     * @return void
     */
    private function authorizeProduct(Request $request, Product $product): void
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if (!in_array($product->type, ['kit', 'assembly'])) {
            abort(422, 'Only kit and assembly products can have components.');
        }
    }

    /**
     * Authorize that the component belongs to the given product.
     *
     * @param Product $product
     * @param ProductComponent $component
     * @return void
     */
    private function authorizeComponent(Product $product, ProductComponent $component): void
    {
        if ($component->parent_product_id !== $product->id) {
            abort(404, 'Component not found for this product.');
        }
    }

    /**
     * Check if adding a component would create a circular reference.
     *
     * A circular reference occurs when product A contains product B,
     * and product B (directly or indirectly) contains product A.
     *
     * @param int $parentId The parent product ID
     * @param int $componentId The component product ID to add
     * @param array $visited Previously visited product IDs (for recursion)
     * @return bool
     */
    private function wouldCreateCircularReference(int $parentId, int $componentId, array $visited = []): bool
    {
        // Check if the component product has components that reference back to parent
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
