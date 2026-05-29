<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Warehouse-access behaviour for the User model.
 *
 * Extracted verbatim from the User god-object (P2-4). Relies on isAdmin()
 * (provided by HasRolesAndPermissions, also used by User).
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
     * Check if user has access to a specific warehouse.
     * Admins have access to all warehouses.
     */
    public function hasWarehouseAccess(int $warehouseId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->warehouses()->where('warehouses.id', $warehouseId)->exists();
    }

    /**
     * Get warehouses the user can access.
     * Admins see all org warehouses.
     */
    public function accessibleWarehouses()
    {
        if (! $this->organization_id) {
            return Warehouse::where('id', 0); // empty query
        }

        if ($this->isAdmin()) {
            return Warehouse::forOrganization($this->organization_id)->active();
        }

        return $this->warehouses()->where('warehouses.organization_id', $this->organization_id)->active();
    }
}
