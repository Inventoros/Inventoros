<?php

declare(strict_types=1);

namespace App\Models\Inventory\Concerns;

use App\Models\Inventory\WarehouseReorderPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Stock-threshold scopes and predicates for the Product model.
 *
 * Extracted verbatim from the Product god-object (P2-5).
 */
trait TracksStockLevels
{
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
            $q->whereColumn("{$table}.stock", '<=', "{$table}.min_stock")
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
                    ->whereColumn("{$table}.stock", '<=', "{$table}.reorder_point");
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
            && $this->stock <= $this->reorder_point;
    }

    /**
     * Check if the product is low on stock.
     */
    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    /**
     * Check if the product is out of stock.
     */
    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }
}
