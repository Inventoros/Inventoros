<?php

declare(strict_types=1);

namespace App\Models\Inventory\Concerns;

use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kit/assembly (bill-of-materials) behaviour for the Product model.
 *
 * Extracted verbatim from the Product god-object (P2-5).
 */
trait ActsAsAssembly
{
    /**
     * Get the components of this kit or assembly (BOM).
     *
     * @return HasMany<ProductComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'parent_product_id');
    }

    /**
     * Get the kits/assemblies that use this product as a component.
     *
     * @return HasMany<ProductComponent, $this>
     */
    public function usedInKits(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'component_product_id');
    }

    /**
     * Get the work orders for this assembly product.
     *
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Check if this product is a kit.
     */
    public function isKit(): bool
    {
        return $this->type === 'kit';
    }

    /**
     * Check if this product is an assembly.
     */
    public function isAssembly(): bool
    {
        return $this->type === 'assembly';
    }

    /**
     * Check if this product is a standard product.
     */
    public function isStandard(): bool
    {
        return $this->type === 'standard';
    }

    /**
     * Calculate available kit stock based on component availability.
     *
     * For kits, stock is not stored directly — it is derived from
     * the minimum number of complete kits that can be assembled
     * from available component stock.
     */
    public function getAvailableKitStock(): int
    {
        if (! $this->isKit()) {
            return $this->stock;
        }

        $components = $this->components()->with('componentProduct')->get();

        if ($components->isEmpty()) {
            return 0;
        }

        $minKits = PHP_INT_MAX;

        foreach ($components as $component) {
            $componentStock = $component->componentProduct->stock;
            $requiredQty = (float) $component->quantity;

            if ($requiredQty <= 0) {
                continue;
            }

            $possibleKits = (int) floor($componentStock / $requiredQty);
            $minKits = min($minKits, $possibleKits);
        }

        return $minKits === PHP_INT_MAX ? 0 : $minKits;
    }

    /**
     * Scope a query to filter products by type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
