<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_CUSTOMERS->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Customer $customer): bool
    {
        return $this->belongsToSameOrganization($user, $customer)
            && $this->hasPermission($user, Permission::VIEW_CUSTOMERS->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_CUSTOMERS->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $this->belongsToSameOrganization($user, $customer)
            && $this->hasPermission($user, Permission::EDIT_CUSTOMERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $this->belongsToSameOrganization($user, $customer)
            && $this->hasPermission($user, Permission::DELETE_CUSTOMERS->value);
    }
}
