<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Barcode Lookup
 */
class BarcodeLookupController extends Controller
{
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
     * @param Request $request The incoming HTTP request
     * @param string $code The scanned code
     * @return JsonResponse
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
        $organizationId = $request->user()->organization_id;
        $code = trim($code);

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

        $product ??= $this->findProductByLink($organizationId, $code);

        if ($product) {
            return response()->json([
                'found' => true,
                'type' => 'product',
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

        $location = $this->findLocation($organizationId, $code);

        if ($location) {
            return response()->json([
                'found' => true,
                'type' => 'location',
                'product' => null,
                'variant' => null,
                'location' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'code' => $location->code,
                    'description' => $location->description,
                    'aisle' => $location->aisle,
                    'shelf' => $location->shelf,
                    'bin' => $location->bin,
                    'warehouse' => $location->warehouse?->name,
                    'product_count' => $location->products()->count(),
                    'products_url' => route('products.index', ['location' => $location->id]),
                ],
            ]);
        }

        return response()->json([
            'found' => false,
            'product' => null,
            'message' => 'No product found with this barcode or SKU.',
        ], 404);
    }

    /**
     * Resolve a product page URL printed in a product QR code. Only links to
     * this installation's own product page match: the scanned URL must equal
     * the URL the app generates for that product id.
     */
    private function findProductByLink(int $organizationId, string $code): ?Product
    {
        if (! str_starts_with($code, 'http://') && ! str_starts_with($code, 'https://')) {
            return null;
        }

        $path = (string) parse_url($code, PHP_URL_PATH);
        if (! preg_match('#/products/(\d+)/?$#', $path, $matches)) {
            return null;
        }

        $id = (int) $matches[1];
        $withoutQuery = (string) strtok($code, '?#');

        if (rtrim($withoutQuery, '/') !== rtrim(route('products.show', $id), '/')) {
            return null;
        }

        return Product::forOrganization($organizationId)
            ->with(['category', 'location', 'suppliers'])
            ->find($id);
    }

    private function findLocation(int $organizationId, string $code): ?ProductLocation
    {
        $query = ProductLocation::query()
            ->where('organization_id', $organizationId)
            ->with('warehouse');

        if (str_starts_with($code, QrCodeService::LOCATION_PREFIX)) {
            $value = substr($code, strlen(QrCodeService::LOCATION_PREFIX));

            if (preg_match('/^#(\d+)$/', $value, $matches)) {
                return $query->find((int) $matches[1]);
            }

            return $value === '' ? null : $query->where('code', $value)->first();
        }

        return $code === '' ? null : $query->where('code', $code)->first();
    }
}
