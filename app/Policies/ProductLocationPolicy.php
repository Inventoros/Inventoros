<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\ProductLocation;
use App\Models\User;

class ProductLocationPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ProductLocation $location): bool
    {
        return $this->belongsToSameOrganization($user, $location)
            && $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_LOCATIONS->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ProductLocation $location): bool
    {
        return $this->belongsToSameOrganization($user, $location)
            && $this->hasPermission($user, Permission::MANAGE_LOCATIONS->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ProductLocation $location): bool
    {
        return $this->belongsToSameOrganization($user, $location)
            && $this->hasPermission($user, Permission::MANAGE_LOCATIONS->value);
    }
}
