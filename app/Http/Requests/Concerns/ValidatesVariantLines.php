<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Validation\Validator;

/**
 * Cross-field checks for line items that may name a product variant.
 *
 * The per-field rules already confirm product_id and product_variant_id each
 * exist in the user's organization; they cannot see each other. This adds the
 * pairing rules as field errors (instead of letting the service throw a flash
 * error after the fact):
 *
 *  - a chosen variant must belong to the line's product, and
 *  - a product that is sold by variant needs a variant on every line.
 */
trait ValidatesVariantLines
{
    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateVariantLines($validator);
            },
        ];
    }

    protected function validateVariantLines(Validator $validator): void
    {
        $items = $this->input('items');
        if (! is_array($items) || $items === []) {
            return;
        }

        $organizationId = $this->user()->organization_id;

        $productIds = collect($items)->pluck('product_id')->filter(fn ($id) => is_numeric($id))->unique();
        $variantIds = collect($items)->pluck('product_variant_id')->filter(fn ($id) => is_numeric($id))->unique();

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

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = $products->get($item['product_id'] ?? null);
            if ($product === null) {
                continue; // the product_id rule already reports this line
            }

            $variantId = $item['product_variant_id'] ?? null;

            if ($variantId === null || $variantId === '') {
                if ($product->has_variants) {
                    $validator->errors()->add(
                        "items.{$index}.product_variant_id",
                        'Choose a variant for this product.'
                    );
                }

                continue;
            }

            $variant = $variants->get($variantId);
            if ($variant !== null && (int) $variant->product_id !== (int) $product->id) {
                $validator->errors()->add(
                    "items.{$index}.product_variant_id",
                    'The selected variant does not belong to this product.'
                );
            }
        }
    }
}
