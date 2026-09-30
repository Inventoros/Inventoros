<?php

declare(strict_types=1);

namespace App\Models\Inventory\Concerns;

use App\Models\Inventory\WarehouseReorderPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Stock-threshold scopes and predicates for the Product model.
 *
 * Thresholds compare the effective stock: the sum of the active variants'
 * stock for a product sold by variant, else the product's own stock.
 */
trait TracksStockLevels
{
    /**
     * SQL for the stock a product has on hand for display, valuation and
     * alerts. A product sold by variant keeps its units on the variants, so
     * its figure is the sum of its active variants' stock; products.stock is
     * not updated when a variant moves. Any other product uses its own stock.
     *
     * @param  string  $table  the products table or its alias in the query
     */
    public static function effectiveStockSql(string $table = 'products'): string
    {
        return '(CASE WHEN '.self::sellsByVariantSql($table)
            ." THEN (SELECT COALESCE(SUM(esv.stock), 0) FROM product_variants esv WHERE esv.product_id = {$table}.id AND esv.is_active = TRUE AND esv.deleted_at IS NULL)"
            ." ELSE {$table}.stock END)";
    }

    /**
     * SQL for the value of that stock at `price` or `purchase_price`. Each
     * active variant counts at its own amount, else the product's.
     *
     * @param  'price'|'purchase_price'  $column
     */
    public static function stockValueSql(string $column, string $table = 'products'): string
    {
        if (! in_array($column, ['price', 'purchase_price'], true)) {
            throw new \InvalidArgumentException("Unknown value column {$column}.");
        }

        return '(CASE WHEN '.self::sellsByVariantSql($table)
            ." THEN (SELECT COALESCE(SUM(vsv.stock * COALESCE(vsv.{$column}, {$table}.{$column}, 0)), 0) FROM product_variants vsv WHERE vsv.product_id = {$table}.id AND vsv.is_active = TRUE AND vsv.deleted_at IS NULL)"
            ." ELSE {$table}.stock * COALESCE({$table}.{$column}, 0) END)";
    }

    /**
     * A product flagged as sold by variant that has at least one variant.
     * Mirrors getTotalStockAttribute(): a flagged product with no variants
     * yet still counts its own stock.
     */
    private static function sellsByVariantSql(string $table): string
    {
        return "{$table}.has_variants = TRUE AND EXISTS (SELECT 1 FROM product_variants hv WHERE hv.product_id = {$table}.id AND hv.deleted_at IS NULL)";
    }

    /**
     * Add the effective stock as `effective_stock`, read by total_stock.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithEffectiveStock($query)
    {
        $table = $this->getTable();

        if ($query->getQuery()->columns === null) {
            $query->select("{$table}.*");
        }

        return $query->selectRaw(self::effectiveStockSql($table).' as effective_stock');
    }

    /**
     * Scope a query to only include products with low stock: the total at or
     * below min_stock, or the on-hand in any warehouse with its own
     * thresholds at or below that warehouse's minimum (blank inherits the
     * product's min_stock).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLowStock($query)
    {
        $table = $this->getTable();

        return $query->where(function ($q) use ($table) {
            $q->whereRaw(self::effectiveStockSql($table)." <= {$table}.min_stock")
                ->orWhereExists(function (QueryBuilder $sub) use ($table) {
                    $min = "coalesce(warehouse_reorder_points.min_stock, {$table}.min_stock)";

                    $sub->selectRaw('1')
                        ->from('warehouse_reorder_points')
                        ->whereColumn('warehouse_reorder_points.product_id', "{$table}.id")
                        ->whereRaw("{$min} is not null")
                        ->whereRaw(WarehouseReorderPoint::onHandSql()." <= {$min}");
                });
        });
    }

    /**
     * Scope a query to only include products that need reorder: the total at
     * or below the product's reorder point, or the on-hand in any warehouse
     * with its own thresholds at or below that warehouse's reorder point
     * (blank fields inherit the product's values). A reorder quantity of 0
     * opts out, as it does at product level.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsReorder($query)
    {
        $table = $this->getTable();

        return $query->where(function ($q) use ($table) {
            $q->where(function ($own) use ($table) {
                $own->whereNotNull("{$table}.reorder_point")
                    ->whereNotNull("{$table}.reorder_quantity")
                    ->where("{$table}.reorder_quantity", '>', 0)
                    ->whereRaw(self::effectiveStockSql($table)." <= {$table}.reorder_point");
            })->orWhereExists(function (QueryBuilder $sub) use ($table) {
                $point = "coalesce(warehouse_reorder_points.reorder_point, {$table}.reorder_point)";
                $quantity = "coalesce(warehouse_reorder_points.reorder_quantity, {$table}.reorder_quantity)";

                $sub->selectRaw('1')
                    ->from('warehouse_reorder_points')
                    ->whereColumn('warehouse_reorder_points.product_id', "{$table}.id")
                    ->whereRaw("{$point} is not null")
                    ->whereRaw("({$quantity} is null or {$quantity} > 0)")
                    ->whereRaw(WarehouseReorderPoint::onHandSql()." <= {$point}");
            });
        });
    }

    /**
     * Check if the product needs to be reordered.
     */
    public function isReorderNeeded(): bool
    {
        return $this->reorder_point !== null
            && $this->reorder_quantity !== null
            && $this->reorder_quantity > 0
            && $this->total_stock <= $this->reorder_point;
    }

    /**
     * Check if the product is low on stock.
     */
    public function isLowStock(): bool
    {
        return $this->total_stock <= $this->min_stock;
    }

    /**
     * Check if the product is out of stock.
     */
    public function isOutOfStock(): bool
    {
        return $this->total_stock <= 0;
    }
}
