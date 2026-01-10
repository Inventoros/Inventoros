<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_USERS->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $this->belongsToSameOrganization($user, $model)
            && $this->hasPermission($user, Permission::VIEW_USERS->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_USERS->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $this->belongsToSameOrganization($user, $model)
            && $this->hasPermission($user, Permission::EDIT_USERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Cannot delete yourself
        if ($user->id === $model->id) {
            return false;
        }

        return $this->belongsToSameOrganization($user, $model)
            && $this->hasPermission($user, Permission::DELETE_USERS->value);
    }
}
