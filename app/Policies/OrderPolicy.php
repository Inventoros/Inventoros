<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order\Order;
use App\Models\User;

class OrderPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_ORDERS->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Order $order): bool
    {
        return $this->belongsToSameOrganization($user, $order)
            && $this->hasPermission($user, Permission::VIEW_ORDERS->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_ORDERS->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Order $order): bool
    {
        return $this->belongsToSameOrganization($user, $order)
            && $this->hasPermission($user, Permission::EDIT_ORDERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Order $order): bool
    {
        return $this->belongsToSameOrganization($user, $order)
            && $this->hasPermission($user, Permission::DELETE_ORDERS->value);
    }

    /**
     * Determine whether the user can approve/reject orders.
     */
    public function approve(User $user, Order $order): bool
    {
        return $this->belongsToSameOrganization($user, $order)
            && $this->hasPermission($user, Permission::APPROVE_ORDERS->value);
    }
}
