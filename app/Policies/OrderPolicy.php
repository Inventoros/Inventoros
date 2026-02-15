<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order\Order;
use App\Models\User;

/**
 * Policy for authorization checks on Order model operations.
 *
 * Handles permissions for viewing, creating, updating, deleting,
 * and approving orders within an organization.
 */
class OrderPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any orders
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_ORDERS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param Order $order The order being viewed
     * @return bool True if the user can view the order
     */
    public function view(User $user, Order $order): bool
    {
        return $this->belongsToSameOrganization($user, $order)
            && $this->hasPermission($user, Permission::VIEW_ORDERS->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create orders
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_ORDERS->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param Order $order The order being updated
     * @return bool True if the user can update the order
     */
    public function update(User $user, Order $order): bool
    {
        return $this->belongsToSameOrganization($user, $order)
            && $this->hasPermission($user, Permission::EDIT_ORDERS->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param Order $order The order being deleted
     * @return bool True if the user can delete the order
     */
    public function delete(User $user, Order $order): bool
    {
        return $this->belongsToSameOrganization($user, $order)
            && $this->hasPermission($user, Permission::DELETE_ORDERS->value);
    }

    /**
     * Determine whether the user can approve/reject orders.
     *
     * @param User $user The user performing the action
     * @param Order $order The order to approve or reject
     * @return bool True if the user can approve the order
     */
    public function approve(User $user, Order $order): bool
    {
        return $this->belongsToSameOrganization($user, $order)
            && $this->hasPermission($user, Permission::APPROVE_ORDERS->value);
    }
}
