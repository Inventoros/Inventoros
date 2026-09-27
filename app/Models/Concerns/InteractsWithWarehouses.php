<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Warehouse;
use App\Services\WarehouseAccessService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Warehouse-access behaviour for the User model.
 *
 * Extracted from the User god-object (P2-4). Access decisions are delegated
 * to WarehouseAccessService.
 */
trait InteractsWithWarehouses
{
    /**
     * @return BelongsToMany<Warehouse, $this>
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user')->withTimestamps();
    }

    /**
     * Check if user has access to a specific warehouse. The rule (admins,
     * access_all_warehouses, assignments, the org restriction setting) lives
     * in WarehouseAccessService.
     */
    public function hasWarehouseAccess(int $warehouseId): bool
    {
        return app(WarehouseAccessService::class)->canAccessWarehouse($this, $warehouseId);
    }

    /**
     * Active warehouses the user can access (for the switcher and pickers).
     */
    public function accessibleWarehouses()
    {
        if (! $this->organization_id) {
            return Warehouse::where('id', 0); // empty query
        }

        return app(WarehouseAccessService::class)->accessibleWarehousesQuery($this)->active();
    }
}
