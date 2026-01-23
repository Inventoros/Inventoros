<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;

class StockAdjustmentPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $this->belongsToSameOrganization($user, $adjustment)
            && $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StockAdjustment $adjustment): bool
    {
        return $this->belongsToSameOrganization($user, $adjustment)
            && $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StockAdjustment $adjustment): bool
    {
        return $this->belongsToSameOrganization($user, $adjustment)
            && $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }
}
