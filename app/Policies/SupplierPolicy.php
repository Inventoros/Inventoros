<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\Supplier;
use App\Models\User;

class SupplierPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_SUPPLIERS->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Supplier $supplier): bool
    {
        return $this->belongsToSameOrganization($user, $supplier)
            && $this->hasPermission($user, Permission::VIEW_SUPPLIERS->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_SUPPLIERS->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Supplier $supplier): bool
    {
        return $this->belongsToSameOrganization($user, $supplier)
            && $this->hasPermission($user, Permission::EDIT_SUPPLIERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->belongsToSameOrganization($user, $supplier)
            && $this->hasPermission($user, Permission::DELETE_SUPPLIERS->value);
    }
}
