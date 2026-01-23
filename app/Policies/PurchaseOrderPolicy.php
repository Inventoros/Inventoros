<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->belongsToSameOrganization($user, $purchaseOrder)
            && $this->hasPermission($user, Permission::VIEW_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->belongsToSameOrganization($user, $purchaseOrder)
            && $this->hasPermission($user, Permission::EDIT_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->belongsToSameOrganization($user, $purchaseOrder)
            && $this->hasPermission($user, Permission::DELETE_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can receive items from purchase orders.
     */
    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->belongsToSameOrganization($user, $purchaseOrder)
            && $this->hasPermission($user, Permission::RECEIVE_PURCHASE_ORDERS->value);
    }
}
