<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

/**
 * Policy for authorization checks on Customer model operations.
 *
 * Handles permissions for viewing, creating, updating, and deleting
 * customers within an organization.
 */
class CustomerPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any customers
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_CUSTOMERS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param Customer $customer The customer being viewed
     * @return bool True if the user can view the customer
     */
    public function view(User $user, Customer $customer): bool
    {
        return $this->belongsToSameOrganization($user, $customer)
            && $this->hasPermission($user, Permission::VIEW_CUSTOMERS->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create customers
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_CUSTOMERS->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param Customer $customer The customer being updated
     * @return bool True if the user can update the customer
     */
    public function update(User $user, Customer $customer): bool
    {
        return $this->belongsToSameOrganization($user, $customer)
            && $this->hasPermission($user, Permission::EDIT_CUSTOMERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param Customer $customer The customer being deleted
     * @return bool True if the user can delete the customer
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $this->belongsToSameOrganization($user, $customer)
            && $this->hasPermission($user, Permission::DELETE_CUSTOMERS->value);
    }
}
