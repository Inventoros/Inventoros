<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Barcode Lookup
 */
class BarcodeLookupController extends Controller
{
    /**
     * Lookup a product by barcode or SKU.
     *
     * A product's own barcode or SKU wins. Otherwise a variant's barcode or
     * SKU resolves to its product, and the response names that variant so a
     * scanner can pick it directly; `variant` is null for a product match.
     *
     * @param Request $request The incoming HTTP request
     * @param string $code The barcode or SKU to lookup
     * @return JsonResponse
     */
    public function lookup(Request $request, string $code): JsonResponse
    {
        return $this->find($request, $code);
    }

    /**
     * Lookup a product by barcode or SKU passed as the `code` query parameter.
     *
     * Used by the in-app scanner over the web session. A query parameter
     * carries any code, including ones containing `/`, which an encoded path
     * segment cannot on servers that reject %2F (Apache's default).
     */
    public function lookupByQuery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
        ]);

        return $this->find($request, $validated['code']);
    }

    private function find(Request $request, string $code): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        // Search by barcode first, then by SKU
        $product = Product::forOrganization($organizationId)
            ->where(function ($query) use ($code) {
                $query->where('barcode', $code)
                    ->orWhere('sku', $code);
            })
            ->with(['category', 'location', 'suppliers'])
            ->first();

        $variant = null;

        if (! $product) {
            $variant = ProductVariant::where('organization_id', $organizationId)
                ->where(function ($query) use ($code) {
                    $query->where('barcode', $code)
                        ->orWhere('sku', $code);
                })
                ->whereHas('product', fn ($query) => $query->where('organization_id', $organizationId))
                // An exact barcode match beats a SKU match on another variant.
                ->orderByRaw('CASE WHEN barcode = ? THEN 0 ELSE 1 END', [$code])
                ->orderBy('id')
                ->first();

            $product = $variant?->product()
                ->with(['category', 'location', 'suppliers'])
                ->first();
        }

        if (!$product) {
            return response()->json([
                'found' => false,
                'product' => null,
                'message' => 'No product found with this barcode or SKU.',
            ], 404);
        }

        return response()->json([
            'found' => true,
            'product' => new ProductResource($product),
            'variant' => $variant ? [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'title' => $variant->title,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'option_values' => $variant->option_values,
                'price' => $variant->price,
                'purchase_price' => $variant->purchase_price,
                'stock' => (int) $variant->stock,
                'is_active' => (bool) $variant->is_active,
            ] : null,
        ]);
    }
}
