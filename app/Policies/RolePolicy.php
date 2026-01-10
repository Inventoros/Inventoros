<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

class RolePolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_ROLES->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Role $role): bool
    {
        return $this->belongsToSameOrganization($user, $role)
            && $this->hasPermission($user, Permission::VIEW_ROLES->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_ROLES->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Role $role): bool
    {
        return $this->belongsToSameOrganization($user, $role)
            && $this->hasPermission($user, Permission::EDIT_ROLES->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Role $role): bool
    {
        return $this->belongsToSameOrganization($user, $role)
            && $this->hasPermission($user, Permission::DELETE_ROLES->value);
    }
}
