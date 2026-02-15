<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Policy for authorization checks on Role model operations.
 *
 * Handles permissions for viewing, creating, updating, and deleting
 * roles within an organization.
 */
class RolePolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any roles
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_ROLES->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param Role $role The role being viewed
     * @return bool True if the user can view the role
     */
    public function view(User $user, Role $role): bool
    {
        return $this->belongsToSameOrganization($user, $role)
            && $this->hasPermission($user, Permission::VIEW_ROLES->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create roles
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_ROLES->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param Role $role The role being updated
     * @return bool True if the user can update the role
     */
    public function update(User $user, Role $role): bool
    {
        return $this->belongsToSameOrganization($user, $role)
            && $this->hasPermission($user, Permission::EDIT_ROLES->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param Role $role The role being deleted
     * @return bool True if the user can delete the role
     */
    public function delete(User $user, Role $role): bool
    {
        return $this->belongsToSameOrganization($user, $role)
            && $this->hasPermission($user, Permission::DELETE_ROLES->value);
    }
}
