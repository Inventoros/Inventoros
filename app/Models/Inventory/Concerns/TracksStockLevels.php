<?php

declare(strict_types=1);

namespace App\Models\Inventory\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Stock-threshold scopes and predicates for the Product model.
 *
 * Extracted verbatim from the Product god-object (P2-5).
 */
trait TracksStockLevels
{
    /**
     * Scope a query to only include products with low stock.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock', '<=', 'min_stock');
    }

    /**
     * Scope a query to only include products that need reorder.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsReorder($query)
    {
        return $query->whereNotNull('reorder_point')
            ->whereNotNull('reorder_quantity')
            ->where('reorder_quantity', '>', 0)
            ->whereColumn('stock', '<=', 'reorder_point');
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
