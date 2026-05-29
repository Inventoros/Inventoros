<?php

declare(strict_types=1);

namespace App\Models\Inventory\Concerns;

/**
 * Profit-calculation accessors for the Product model.
 *
 * Extracted verbatim from the Product god-object (P2-5).
 */
trait CalculatesProductProfit
{
    /**
     * Get the profit per unit.
     */
    public function getProfitAttribute(): float
    {
        if (! $this->purchase_price || ! $this->price) {
            return 0;
        }

        return $this->price - $this->purchase_price;
    }

    /**
     * Get the profit margin percentage.
     */
    public function getProfitMarginAttribute(): float
    {
        if (! $this->purchase_price || ! $this->price || $this->price == 0) {
            return 0;
        }

        return (($this->price - $this->purchase_price) / $this->price) * 100;
    }

    /**
     * Get the total profit for all stock.
     */
    public function getTotalProfitAttribute(): float
    {
        return $this->profit * $this->stock;
    }
}
