<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\Supplier;
use App\Models\User;

/**
 * Policy for authorization checks on Supplier model operations.
 *
 * Handles permissions for viewing, creating, updating, and deleting
 * suppliers within an organization.
 */
class SupplierPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any suppliers
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_SUPPLIERS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param Supplier $supplier The supplier being viewed
     * @return bool True if the user can view the supplier
     */
    public function view(User $user, Supplier $supplier): bool
    {
        return $this->belongsToSameOrganization($user, $supplier)
            && $this->hasPermission($user, Permission::VIEW_SUPPLIERS->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create suppliers
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_SUPPLIERS->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param Supplier $supplier The supplier being updated
     * @return bool True if the user can update the supplier
     */
    public function update(User $user, Supplier $supplier): bool
    {
        return $this->belongsToSameOrganization($user, $supplier)
            && $this->hasPermission($user, Permission::EDIT_SUPPLIERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param Supplier $supplier The supplier being deleted
     * @return bool True if the user can delete the supplier
     */
    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->belongsToSameOrganization($user, $supplier)
            && $this->hasPermission($user, Permission::DELETE_SUPPLIERS->value);
    }
}
