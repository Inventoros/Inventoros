<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;

/**
 * Policy for authorization checks on StockAdjustment model operations.
 *
 * Handles permissions for viewing, creating, updating, and deleting
 * stock adjustments within an organization.
 */
class StockAdjustmentPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any stock adjustments
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param StockAdjustment $adjustment The stock adjustment being viewed
     * @return bool True if the user can view the stock adjustment
     */
    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $this->belongsToSameOrganization($user, $adjustment)
            && $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create stock adjustments
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param StockAdjustment $adjustment The stock adjustment being updated
     * @return bool True if the user can update the stock adjustment
     */
    public function update(User $user, StockAdjustment $adjustment): bool
    {
        return $this->belongsToSameOrganization($user, $adjustment)
            && $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param StockAdjustment $adjustment The stock adjustment being deleted
     * @return bool True if the user can delete the stock adjustment
     */
    public function delete(User $user, StockAdjustment $adjustment): bool
    {
        return $this->belongsToSameOrganization($user, $adjustment)
            && $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }
}
