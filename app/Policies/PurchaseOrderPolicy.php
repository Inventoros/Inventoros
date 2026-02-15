<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;

/**
 * Policy for authorization checks on PurchaseOrder model operations.
 *
 * Handles permissions for viewing, creating, updating, deleting,
 * and receiving purchase orders within an organization.
 */
class PurchaseOrderPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any purchase orders
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param PurchaseOrder $purchaseOrder The purchase order being viewed
     * @return bool True if the user can view the purchase order
     */
    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->belongsToSameOrganization($user, $purchaseOrder)
            && $this->hasPermission($user, Permission::VIEW_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create purchase orders
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param PurchaseOrder $purchaseOrder The purchase order being updated
     * @return bool True if the user can update the purchase order
     */
    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->belongsToSameOrganization($user, $purchaseOrder)
            && $this->hasPermission($user, Permission::EDIT_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param PurchaseOrder $purchaseOrder The purchase order being deleted
     * @return bool True if the user can delete the purchase order
     */
    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->belongsToSameOrganization($user, $purchaseOrder)
            && $this->hasPermission($user, Permission::DELETE_PURCHASE_ORDERS->value);
    }

    /**
     * Determine whether the user can receive items from purchase orders.
     *
     * @param User $user The user performing the action
     * @param PurchaseOrder $purchaseOrder The purchase order to receive items from
     * @return bool True if the user can receive items from the purchase order
     */
    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->belongsToSameOrganization($user, $purchaseOrder)
            && $this->hasPermission($user, Permission::RECEIVE_PURCHASE_ORDERS->value);
    }
}
