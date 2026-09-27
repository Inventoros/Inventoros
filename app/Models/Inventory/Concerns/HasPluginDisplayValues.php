<?php

declare(strict_types=1);

namespace App\Models\Inventory\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * The single place the documented display filters run.
 *
 * `display_name` passes the product name through `product_display_name`, and
 * `display_price` passes the price through `product_price_display`. The raw
 * `name` and `price` columns are never changed, so edit forms, exports and
 * order pricing keep working on real values; only surfaces that show a
 * product to a person (the product list and detail pages, the REST product
 * resource and global search) read the display values.
 */
trait HasPluginDisplayValues
{
    /**
     * The attributes a display surface appends to the product.
     *
     * @var array<int, string>
     */
    public const DISPLAY_ATTRIBUTES = ['display_name', 'display_price'];

    /**
     * Append the plugin-filtered display values when this product is serialised.
     */
    public function withDisplayValues(): static
    {
        return $this->append(self::DISPLAY_ATTRIBUTES);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(
            fn (): string => (string) apply_filters('product_display_name', (string) $this->name, $this)
        );
    }

    /**
     * @return Attribute<mixed, never>
     */
    protected function displayPrice(): Attribute
    {
        return Attribute::get(function (): mixed {
            $price = $this->price === null ? null : (float) $this->price;

            return apply_filters('product_price_display', $price, $this);
        });
    }
}
