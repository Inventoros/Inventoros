<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Policy for authorization checks on User model operations.
 *
 * Handles permissions for viewing, creating, updating, and deleting
 * users within an organization.
 */
class UserPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any users
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_USERS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param User $model The user being viewed
     * @return bool True if the user can view the model
     */
    public function view(User $user, User $model): bool
    {
        return $this->belongsToSameOrganization($user, $model)
            && $this->hasPermission($user, Permission::VIEW_USERS->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create users
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_USERS->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param User $model The user being updated
     * @return bool True if the user can update the model
     */
    public function update(User $user, User $model): bool
    {
        return $this->belongsToSameOrganization($user, $model)
            && $this->hasPermission($user, Permission::EDIT_USERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param User $model The user being deleted
     * @return bool True if the user can delete the model
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
