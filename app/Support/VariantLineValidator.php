<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;

/**
 * Cross-field checks for lines (order, purchase order, stock adjustment) that
 * may name a product variant.
 *
 * Per-field `exists` rules confirm product_id and product_variant_id each
 * belong to the organization, but cannot see each other. This checks the
 * pairing:
 *
 *  - a chosen variant must belong to the line's product, and
 *  - when $requireVariant is true, a product sold by variant needs one.
 *
 * Surfaces with existing external clients (REST, MCP) pass
 * $requireVariant = false so the variant stays optional for them.
 */
final class VariantLineValidator
{
    public const MISSING = 'Choose a variant for this product.';

    public const MISMATCH = 'The selected variant does not belong to this product.';

    /**
     * @param  array<int|string, mixed>  $lines  each a {product_id, product_variant_id?} array
     * @return array<int|string, string> error message keyed by the offending line's key
     */
    public static function errors(array $lines, int $organizationId, bool $requireVariant = true): array
    {
        $lines = array_filter($lines, 'is_array');
        if ($lines === []) {
            return [];
        }

        $productIds = collect($lines)->pluck('product_id')->filter(fn ($id) => is_numeric($id))->unique();
        $variantIds = collect($lines)->pluck('product_variant_id')->filter(fn ($id) => is_numeric($id))->unique();

        $products = Product::where('organization_id', $organizationId)
            ->whereIn('id', $productIds)
            ->get(['id', 'has_variants'])
            ->keyBy('id');

        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::where('organization_id', $organizationId)
                ->whereIn('id', $variantIds)
                ->get(['id', 'product_id'])
                ->keyBy('id');

        $errors = [];

        foreach ($lines as $key => $line) {
            $product = $products->get($line['product_id'] ?? null);
            if ($product === null) {
                continue; // the product_id rule already reports this line
            }

            $variantId = $line['product_variant_id'] ?? null;

            if ($variantId === null || $variantId === '') {
                if ($requireVariant && $product->has_variants) {
                    $errors[$key] = self::MISSING;
                }

                continue;
            }

            $variant = $variants->get($variantId);
            if ($variant !== null && (int) $variant->product_id !== (int) $product->id) {
                $errors[$key] = self::MISMATCH;
            }
        }

        return $errors;
    }
}
