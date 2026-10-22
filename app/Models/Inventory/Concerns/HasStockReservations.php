<?php

declare(strict_types=1);

namespace App\Models\Inventory\Concerns;

use App\Models\Inventory\StockReservation;
use App\Services\StockReservationService;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reserved and available-to-promise stock for display (product pages, the
 * REST resource, GraphQL and MCP payloads). Used by Product and
 * ProductVariant.
 *
 * `reserved_stock` is what active stock reservations hold; `available_stock`
 * is on hand less that, passed through the `product_available_quantity`
 * filter. The filter changes only what is shown: every availability check
 * reads StockReservationService under the row lock instead.
 *
 * Lists preload the reserved figures in one query
 * (StockReservationService::preloadReserved()) instead of one per row.
 */
trait HasStockReservations
{
    /**
     * Reserved units set by a preload, or null to query on demand.
     */
    protected ?int $preloadedReservedStock = null;

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class, $this->reservationForeignKey());
    }

    public function setPreloadedReservedStock(int $reserved): void
    {
        $this->preloadedReservedStock = $reserved;
    }

    /**
     * Units held by active stock reservations.
     */
    public function getReservedStockAttribute(): int
    {
        if ($this->preloadedReservedStock !== null) {
            return $this->preloadedReservedStock;
        }

        $reservations = app(StockReservationService::class);

        return $this->isVariantForReservations()
            ? $reservations->reservedTotal((int) $this->product_id, (int) $this->id)
            : $reservations->reserved($this);
    }

    /**
     * On hand less reserved, as plugins choose to show it
     * (`product_available_quantity`). Display only.
     */
    public function getAvailableStockAttribute(): int
    {
        $onHand = $this->isVariantForReservations() ? (int) $this->stock : (int) $this->total_stock;
        $available = $onHand - $this->reserved_stock;

        $product = $this->isVariantForReservations() ? $this->product : $this;
        $variant = $this->isVariantForReservations() ? $this : null;

        $filtered = apply_filters('product_available_quantity', $available, $product, $variant);

        return is_numeric($filtered) ? (int) $filtered : $available;
    }

    /**
     * The reservation figures a page or payload shows.
     *
     * @return array{on_hand: int, reserved: int, available: int}
     */
    public function stockAvailability(): array
    {
        return [
            'on_hand' => $this->isVariantForReservations() ? (int) $this->stock : (int) $this->total_stock,
            'reserved' => $this->reserved_stock,
            'available' => $this->available_stock,
        ];
    }

    private function isVariantForReservations(): bool
    {
        return $this->reservationForeignKey() === 'product_variant_id';
    }

    private function reservationForeignKey(): string
    {
        return $this->getTable() === 'product_variants' ? 'product_variant_id' : 'product_id';
    }
}
