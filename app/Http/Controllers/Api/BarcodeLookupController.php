<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Inventory\ProductVariant;
use App\Services\ScanLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Barcode Lookup
 */
class BarcodeLookupController extends Controller
{
    public function __construct(private readonly ScanLookupService $scans) {}

    /**
     * Lookup a product or storage location by a scanned code.
     *
     * Resolves, in order: a product's own barcode or SKU; a variant's barcode
     * or SKU (the response names that variant so a scanner can pick it
     * directly; `variant` is null for a product match); a product deep link
     * printed in a QR code; then a location QR code (`LOC:<code>` or
     * `LOC:#<id>`) or a plain location code. The response carries `type` =
     * `product` or `location`.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  string  $code  The scanned code
     */
    public function lookup(Request $request, string $code): JsonResponse
    {
        return $this->find($request, $code);
    }

    /**
     * Lookup a product or location by the `code` query parameter.
     *
     * Used by the in-app scanner over the web session. A query parameter
     * carries any code, including ones containing `/`, which an encoded path
     * segment cannot on servers that reject %2F (Apache's default).
     */
    public function lookupByQuery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Product deep links from QR codes can be longer than a barcode.
            'code' => ['required', 'string', 'max:2048'],
        ]);

        return $this->find($request, $validated['code']);
    }

    private function find(Request $request, string $code): JsonResponse
    {
        $match = $this->scans->resolve((int) $request->user()->organization_id, $code);

        if ($match === null) {
            return response()->json([
                'found' => false,
                'product' => null,
                'message' => 'No product found with this barcode or SKU.',
            ], 404);
        }

        if ($match['type'] === 'location') {
            return response()->json([
                'found' => true,
                'type' => 'location',
                'product' => null,
                'variant' => null,
                'location' => $this->scans->locationSummary($match['location']),
            ]);
        }

        return response()->json([
            'found' => true,
            'type' => 'product',
            'product' => new ProductResource($match['product']),
            'variant' => $this->variantSummary($match['variant']),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function variantSummary(?ProductVariant $variant): ?array
    {
        if ($variant === null) {
            return null;
        }

        return [
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
        ];
    }
}
