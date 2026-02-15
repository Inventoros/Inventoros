<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\ProductCategory;
use App\Models\User;

/**
 * Policy for authorization checks on ProductCategory model operations.
 *
 * Handles permissions for viewing, creating, updating, and deleting
 * product categories within an organization.
 */
class ProductCategoryPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any product categories
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param ProductCategory $category The product category being viewed
     * @return bool True if the user can view the product category
     */
    public function view(User $user, ProductCategory $category): bool
    {
        return $this->belongsToSameOrganization($user, $category)
            && $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can create product categories
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_CATEGORIES->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param ProductCategory $category The product category being updated
     * @return bool True if the user can update the product category
     */
    public function update(User $user, ProductCategory $category): bool
    {
        return $this->belongsToSameOrganization($user, $category)
            && $this->hasPermission($user, Permission::MANAGE_CATEGORIES->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param ProductCategory $category The product category being deleted
     * @return bool True if the user can delete the product category
     */
    public function delete(User $user, ProductCategory $category): bool
    {
        return $this->belongsToSameOrganization($user, $category)
            && $this->hasPermission($user, Permission::MANAGE_CATEGORIES->value);
    }
}
