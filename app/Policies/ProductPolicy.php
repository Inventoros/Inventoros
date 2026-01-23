<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Inventory\Product;
use App\Models\User;

class ProductPolicy extends BasePolicy
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
    public function view(User $user, Product $product): bool
    {
        return $this->belongsToSameOrganization($user, $product)
            && $this->hasPermission($user, Permission::VIEW_PRODUCTS->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::CREATE_PRODUCTS->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Product $product): bool
    {
        return $this->belongsToSameOrganization($user, $product)
            && $this->hasPermission($user, Permission::EDIT_PRODUCTS->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Product $product): bool
    {
        return $this->belongsToSameOrganization($user, $product)
            && $this->hasPermission($user, Permission::DELETE_PRODUCTS->value);
    }

    /**
     * Determine whether the user can manage stock.
     */
    public function manageStock(User $user, Product $product): bool
    {
        return $this->belongsToSameOrganization($user, $product)
            && $this->hasPermission($user, Permission::MANAGE_STOCK->value);
    }
}
