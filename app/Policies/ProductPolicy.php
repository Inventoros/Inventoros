<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\Product;
use App\Models\User;

/**
 * Policy for authorization checks on Product model operations.
 *
 * Handles permissions for viewing, creating, updating, deleting,
 * and managing stock for products within an organization.
 */
class ProductPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any products
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param Product $product The product being viewed
     * @return bool True if the user can view the product
     */
    public function view(User $user, Product $product): bool
    {
        return $this->belongsToSameOrganization($user, $product)
            && $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create products
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_PRODUCTS->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param Product $product The product being updated
     * @return bool True if the user can update the product
     */
    public function update(User $user, Product $product): bool
    {
        return $this->belongsToSameOrganization($user, $product)
            && $this->hasPermission($user, Permission::EDIT_PRODUCTS->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param Product $product The product being deleted
     * @return bool True if the user can delete the product
     */
    public function delete(User $user, Product $product): bool
    {
        return $this->belongsToSameOrganization($user, $product)
            && $this->hasPermission($user, Permission::DELETE_PRODUCTS->value);
    }

    /**
     * Determine whether the user can manage stock.
     *
     * @param User $user The user performing the action
     * @param Product $product The product to manage stock for
     * @return bool True if the user can manage stock for the product
     */
    public function manageStock(User $user, Product $product): bool
    {
        return $this->belongsToSameOrganization($user, $product)
            && $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }
}
