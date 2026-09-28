<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProductVariant\StoreProductVariantRequest;
use App\Http\Requests\Api\ProductVariant\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Services\ApprovalService;
use App\Services\ProductService;
use App\Services\WarehouseAccessService;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * @tags Product Variants
 */
class ProductVariantController extends Controller
{
    /**
     * List variants for a product.
     */
    #[QueryParameter('is_active', description: 'Filter by active status', type: 'boolean')]
    #[QueryParameter('low_stock', description: 'Show only variants below minimum stock', type: 'boolean')]
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
     * @param  Request  $request  The incoming HTTP request containing variant data
     * @param  Product  $product  The product to create variant for
     */
    public function store(StoreProductVariantRequest $request, Product $product): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        $validated = $request->validated();

        $validated['product_id'] = $product->id;
        $validated['organization_id'] = $product->organization_id;
        $validated['stock'] = $validated['stock'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['position'] = $validated['position'] ?? $product->variants()->count();

        $variant = DB::transaction(function () use ($product, $validated) {
            $variant = ProductVariant::create($validated);
            app(ProductService::class)->recordOpeningVariantStock($variant);

            // Mark product as having variants
            if (! $product->has_variants) {
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
     * @param  Request  $request  The incoming HTTP request
     * @param  Product  $product  The parent product
     * @param  ProductVariant  $variant  The variant to display
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
     * @param  Request  $request  The incoming HTTP request containing updated variant data
     * @param  Product  $product  The parent product
     * @param  ProductVariant  $variant  The variant to update
     */
    public function update(UpdateProductVariantRequest $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variant not found', 'error' => 'not_found'], 404);
        }

        $validated = $request->validated();

        $variant->update($validated);

        return response()->json([
            'message' => 'Variant updated successfully',
            'data' => new ProductVariantResource($variant),
        ]);
    }

    /**
     * Remove the specified variant.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Product  $product  The parent product
     * @param  ProductVariant  $variant  The variant to delete
     */
    public function destroy(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ($product->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Product not found', 'error' => 'not_found'], 404);
        }

        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variant not found', 'error' => 'not_found'], 404);
        }

        // A variant with stock or history keeps its row: deleting it would
        // strand the stock and orphan the lines that point at it.
        if (app(ProductService::class)->variantIdsInUse([$variant->id]) !== []) {
            return response()->json([
                'message' => ProductService::VARIANT_IN_USE_MESSAGE,
                'error' => 'variant_in_use',
            ], 422);
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
     * @param  Request  $request  The incoming HTTP request containing adjustment data
     * @param  Product  $product  The parent product
     * @param  ProductVariant  $variant  The variant to adjust stock for
     */
    public function adjustStock(Request $request, Product $product, ProductVariant $variant, ApprovalService $approvals): JsonResponse
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

        // A variant's stock sits at its parent's location, so a restricted
        // user may only adjust variants of products in their warehouses (the
        // same rule the stock adjustment surfaces apply to a bin).
        app(WarehouseAccessService::class)->authorizeLocation($request->user(), $product->location_id);

        // Applied now, or held for approval (202) when the organization's
        // approval rules cover it: the same path every other manual
        // adjustment surface takes.
        try {
            $adjustment = $approvals->submitStockAdjustment(
                user: $request->user(),
                product: $product,
                variant: $variant,
                quantity: (int) $validated['quantity'],
                type: $validated['type'],
                reason: $validated['reason'] ?? null,
                notes: $validated['notes'] ?? null,
            );
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['quantity' => [$e->getMessage()]],
            ], 422);
        }

        if ($adjustment instanceof StockAdjustmentRequest) {
            return response()->json([
                'message' => 'Stock adjustment submitted for approval; stock changes once it is approved',
                'status' => 'pending_approval',
                'data' => new ProductVariantResource($variant->refresh()),
                'request' => $approvals->describe(ApprovalService::STOCK_ADJUSTMENT, $adjustment->load(['product', 'variant', 'requester'])),
            ], 202);
        }

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
     * @param  Request  $request  The incoming HTTP request containing multiple variant data
     * @param  Product  $product  The product to create variants for
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

                $variant = ProductVariant::create($variantData);
                app(ProductService::class)->recordOpeningVariantStock($variant);
                $created[] = $variant;
            }

            if (! $product->has_variants) {
                $product->update(['has_variants' => true]);
            }

            return $created;
        });

        return response()->json([
            'message' => count($variants).' variants created successfully',
            'data' => ProductVariantResource::collection($variants),
        ], 201);
    }
}
