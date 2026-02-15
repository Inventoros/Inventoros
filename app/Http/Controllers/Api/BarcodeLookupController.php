<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Inventory\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API Controller for barcode lookup.
 *
 * Handles looking up products by barcode or SKU.
 */
class BarcodeLookupController extends Controller
{
    /**
     * Lookup a product by barcode or SKU.
     *
     * @param Request $request The incoming HTTP request
     * @param string $code The barcode or SKU to lookup
     * @return JsonResponse
     */
    public function lookup(Request $request, string $code): JsonResponse
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
        ]);
    }
}
