<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Plugin;
use App\Models\User;

class PluginPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PLUGINS->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Plugin $plugin): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PLUGINS->value);
    }

    /**
     * Determine whether the user can create/install models.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_PLUGINS->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Plugin $plugin): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_PLUGINS->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Plugin $plugin): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_PLUGINS->value);
    }

    /**
     * Determine whether the user can activate/deactivate plugins.
     */
    public function manage(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_PLUGINS->value);
    }
}
