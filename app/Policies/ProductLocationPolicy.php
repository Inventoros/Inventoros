<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\ProductLocation;
use App\Models\User;

/**
 * Policy for authorization checks on ProductLocation model operations.
 *
 * Handles permissions for viewing, creating, updating, and deleting
 * product locations within an organization.
 */
class ProductLocationPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any product locations
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param ProductLocation $location The product location being viewed
     * @return bool True if the user can view the product location
     */
    public function view(User $user, ProductLocation $location): bool
    {
        return $this->belongsToSameOrganization($user, $location)
            && $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create product locations
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_LOCATIONS->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param ProductLocation $location The product location being updated
     * @return bool True if the user can update the product location
     */
    public function update(User $user, ProductLocation $location): bool
    {
        return $this->belongsToSameOrganization($user, $location)
            && $this->hasPermission($user, Permission::MANAGE_LOCATIONS->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param ProductLocation $location The product location being deleted
     * @return bool True if the user can delete the product location
     */
    public function delete(User $user, ProductLocation $location): bool
    {
        return $this->belongsToSameOrganization($user, $location)
            && $this->hasPermission($user, Permission::MANAGE_LOCATIONS->value);
    }
}
