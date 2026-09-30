<?php

declare(strict_types=1);

namespace App\Models\Inventory\Concerns;

use App\Models\Inventory\ProductOption;
use App\Models\Inventory\ProductVariant;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variant- and option-related behaviour for the Product model.
 *
 * Extracted verbatim from the Product god-object (P2-5).
 */
trait HasProductVariants
{
    /**
     * Get the options for this product.
     *
     * @return HasMany<ProductOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->ordered();
    }

    /**
     * Get the variants for this product.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->ordered();
    }

    /**
     * Get active variants for this product.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->active()->ordered();
    }

    /**
     * The stock on hand: the sum of the active variants' stock for a product
     * sold by variant, else the product's own stock. Uses `effective_stock`
     * when the query selected it (withEffectiveStock()), so lists do not
     * query per row.
     */
    public function getTotalStockAttribute(): int
    {
        if (array_key_exists('effective_stock', $this->attributes) && $this->attributes['effective_stock'] !== null) {
            return (int) $this->attributes['effective_stock'];
        }

        if ($this->has_variants && $this->variants()->exists()) {
            // SUM() is a numeric string on MySQL/PostgreSQL; the return type is int.
            return (int) $this->variants()->where('is_active', true)->sum('stock');
        }

        return (int) $this->stock;
    }

    /**
     * Get the price range for products with variants.
     *
     * @return array{min: string, max: string}
     */
    public function getPriceRangeAttribute(): array
    {
        if (! $this->has_variants || ! $this->variants()->exists()) {
            return ['min' => $this->price, 'max' => $this->price];
        }

        $variants = $this->variants()->whereNotNull('price')->get();
        if ($variants->isEmpty()) {
            return ['min' => $this->price, 'max' => $this->price];
        }

        return [
            'min' => $variants->min('price') ?? $this->price,
            'max' => $variants->max('price') ?? $this->price,
        ];
    }

    /**
     * Find a variant by its option values.
     *
     * @param  array<string, string>  $optionValues
     */
    public function findVariant(array $optionValues): ?ProductVariant
    {
        return $this->variants()->get()->first(fn ($v) => $v->matchesOptions($optionValues));
    }

    /**
     * Generate all possible variant combinations from options.
     *
     * @return array<int, array<string, string>>
     */
    public function generateVariantCombinations(): array
    {
        $options = $this->options()->ordered()->get();

        if ($options->isEmpty()) {
            return [];
        }

        $combinations = [[]];

        foreach ($options as $option) {
            $newCombinations = [];
            foreach ($combinations as $combination) {
                foreach ($option->values as $value) {
                    $newCombinations[] = array_merge($combination, [$option->name => $value]);
                }
            }
            $combinations = $newCombinations;
        }

        return $combinations;
    }
}
