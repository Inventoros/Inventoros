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
     * Get the total stock across all variants (or product stock if no variants).
     */
    public function getTotalStockAttribute(): int
    {
        if ($this->has_variants && $this->variants()->exists()) {
            return $this->variants()->sum('stock');
        }

        return $this->stock;
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
