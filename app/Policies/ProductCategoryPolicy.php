<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\ProductCategory;
use App\Models\User;

class ProductCategoryPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ProductCategory $category): bool
    {
        return $this->belongsToSameOrganization($user, $category)
            && $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_CATEGORIES->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ProductCategory $category): bool
    {
        return $this->belongsToSameOrganization($user, $category)
            && $this->hasPermission($user, Permission::MANAGE_CATEGORIES->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ProductCategory $category): bool
    {
        return $this->belongsToSameOrganization($user, $category)
            && $this->hasPermission($user, Permission::MANAGE_CATEGORIES->value);
    }
}
