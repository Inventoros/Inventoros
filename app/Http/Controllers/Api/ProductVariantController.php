<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductVariantResource;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * API Controller for managing product variants.
 *
 * Handles RESTful API operations for product variant CRUD operations
 * and stock adjustments for variants.
 */
class ProductVariantController extends Controller
{
    /**
     * Display a listing of variants for a product.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The product to list variants for
     * @return AnonymousResourceCollection|JsonResponse
     */
    public function index(Request $request, Product $product): AnonymousResourceCollection|JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        $variants = $product->variants()
            ->when($request->input('is_active') !== null, function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->input('low_stock'), function ($query) {
                $query->lowStock();
            })
            ->ordered()
            ->get();

        return ProductVariantResource::collection($variants);
    }

    /**
     * Store a newly created variant.
     *
     * @param Request $request The incoming HTTP request containing variant data
     * @param Product $product The product to create variant for
     * @return JsonResponse
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'option_values' => ['required', 'array'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'in:kg,lb,oz,g'],
            'is_active' => ['nullable', 'boolean'],
            'requires_shipping' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ]);

        $validated['product_id'] = $product->id;
        $validated['organization_id'] = $product->organization_id;
        $validated['stock'] = $validated['stock'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['position'] = $validated['position'] ?? $product->variants()->count();

        $variant = DB::transaction(function () use ($product, $validated) {
            $variant = ProductVariant::create($validated);

            // Mark product as having variants
            if (!$product->has_variants) {
                $product->update(['has_variants' => true]);
            }

            return $variant;
        });

        return response()->json([
            'message' => 'Variant created successfully',
            'data' => new ProductVariantResource($variant),
        ], 201);
    }

    /**
     * Display the specified variant.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product
     * @param ProductVariant $variant The variant to display
     * @return JsonResponse
     */
    public function show(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variant not found', 'error' => 'not_found'], 404);
        }

        return response()->json([
            'data' => new ProductVariantResource($variant),
        ]);
    }

    /**
     * Update the specified variant.
     *
     * @param Request $request The incoming HTTP request containing updated variant data
     * @param Product $product The parent product
     * @param ProductVariant $variant The variant to update
     * @return JsonResponse
     */
    public function update(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variant not found', 'error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'option_values' => ['sometimes', 'array'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'in:kg,lb,oz,g'],
            'is_active' => ['nullable', 'boolean'],
            'requires_shipping' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ]);

        $variant->update($validated);

        return response()->json([
            'message' => 'Variant updated successfully',
            'data' => new ProductVariantResource($variant),
        ]);
    }

    /**
     * Remove the specified variant.
     *
     * @param Request $request The incoming HTTP request
     * @param Product $product The parent product
     * @param ProductVariant $variant The variant to delete
     * @return JsonResponse
     */
    public function destroy(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variant not found', 'error' => 'not_found'], 404);
        }

        DB::transaction(function () use ($product, $variant) {
            $variant->delete();

            // If no more variants, mark product as not having variants
            if ($product->variants()->count() === 0) {
                $product->update(['has_variants' => false]);
            }
        });

        return response()->json([
            'message' => 'Variant deleted successfully',
        ]);
    }

    /**
     * Adjust stock for a variant.
     *
     * @param Request $request The incoming HTTP request containing adjustment data
     * @param Product $product The parent product
     * @param ProductVariant $variant The variant to adjust stock for
     * @return JsonResponse
     */
    public function adjustStock(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variant not found', 'error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer'],
            'type' => ['required', 'string', 'in:increase,decrease,recount,damage,return,received'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $adjustment = StockAdjustment::adjustVariant(
            $variant,
            $validated['quantity'],
            $validated['type'],
            $validated['reason'] ?? null,
            $validated['notes'] ?? null
        );

        $variant->refresh();

        return response()->json([
            'message' => 'Stock adjusted successfully',
            'data' => new ProductVariantResource($variant),
            'adjustment' => [
                'id' => $adjustment->id,
                'quantity_before' => $adjustment->quantity_before,
                'quantity_after' => $adjustment->quantity_after,
                'adjustment_quantity' => $adjustment->adjustment_quantity,
            ],
        ]);
    }

    /**
     * Bulk create variants from option combinations.
     *
     * @param Request $request The incoming HTTP request containing multiple variant data
     * @param Product $product The product to create variants for
     * @return JsonResponse
     */
    public function bulkCreate(Request $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.barcode' => ['nullable', 'string', 'max:255'],
            'variants.*.option_values' => ['required', 'array'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
        ]);

        $variants = DB::transaction(function () use ($product, $validated) {
            $created = [];
            $position = $product->variants()->count();

            foreach ($validated['variants'] as $variantData) {
                $variantData['product_id'] = $product->id;
                $variantData['organization_id'] = $product->organization_id;
                $variantData['stock'] = $variantData['stock'] ?? 0;
                $variantData['is_active'] = $variantData['is_active'] ?? true;
                $variantData['position'] = $position++;

                $created[] = ProductVariant::create($variantData);
            }

            if (!$product->has_variants) {
                $product->update(['has_variants' => true]);
            }

            return $created;
        });

        return response()->json([
            'message' => count($variants) . ' variants created successfully',
            'data' => ProductVariantResource::collection($variants),
        ], 201);
    }
}
