<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;

/**
 * Resolves a scanned code to a product, variant, or storage location.
 *
 * The one resolution order shared by the in-app scanner, the REST lookup and
 * the MCP lookup tool:
 *   1. a product's own barcode or SKU,
 *   2. a variant's barcode or SKU (exact barcode beats SKU), returning its
 *      product and the variant,
 *   3. a product deep link printed in a product QR code (only this
 *      installation's own product URL),
 *   4. a location QR code (`LOC:<code>` or `LOC:#<id>`) or a plain location
 *      code.
 * Every step is scoped to the given organization.
 */
final class ScanLookupService
{
    /**
     * @return array{type: 'product', product: Product, variant: ProductVariant|null}|array{type: 'location', location: ProductLocation}|null
     */
    public function resolve(int $organizationId, string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $product = $this->findProduct($organizationId, $code);
        if ($product) {
            return ['type' => 'product', 'product' => $product, 'variant' => null];
        }

        $variant = $this->findVariant($organizationId, $code);
        $product = $variant?->product()->with(['category', 'location', 'suppliers'])->first();
        if ($variant && $product) {
            return ['type' => 'product', 'product' => $product, 'variant' => $variant];
        }

        $product = $this->findProductByLink($organizationId, $code);
        if ($product) {
            return ['type' => 'product', 'product' => $product, 'variant' => null];
        }

        $location = $this->findLocation($organizationId, $code);
        if ($location) {
            return ['type' => 'location', 'location' => $location];
        }

        return null;
    }

    /**
     * Serializable summary of a location for scanner responses.
     *
     * @return array<string, mixed>
     */
    public function locationSummary(ProductLocation $location): array
    {
        return [
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
        ];
    }

    private function findProduct(int $organizationId, string $code): ?Product
    {
        return Product::forOrganization($organizationId)
            ->where(function ($query) use ($code) {
                $query->where('barcode', $code)
                    ->orWhere('sku', $code);
            })
            ->with(['category', 'location', 'suppliers'])
            ->first();
    }

    private function findVariant(int $organizationId, string $code): ?ProductVariant
    {
        return ProductVariant::where('organization_id', $organizationId)
            ->where(function ($query) use ($code) {
                $query->where('barcode', $code)
                    ->orWhere('sku', $code);
            })
            ->whereHas('product', fn ($query) => $query->where('organization_id', $organizationId))
            // An exact barcode match beats a SKU match on another variant.
            ->orderByRaw('CASE WHEN barcode = ? THEN 0 ELSE 1 END', [$code])
            ->orderBy('id')
            ->first();
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

        return $query->where('code', $code)->first();
    }
}
