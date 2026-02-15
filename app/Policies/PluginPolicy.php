<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Plugin;
use App\Models\User;

/**
 * Policy for authorization checks on Plugin model operations.
 *
 * Handles permissions for viewing, installing, updating, deleting,
 * and managing plugins within the system.
 */
class PluginPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any plugins
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PLUGINS->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param Plugin $plugin The plugin being viewed
     * @return bool True if the user can view the plugin
     */
    public function view(User $user, Plugin $plugin): bool
    {
        return $this->hasPermission($user, Permission::VIEW_PLUGINS->value);
    }

    /**
     * Determine whether the user can create/install models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can install plugins
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_PLUGINS->value);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user The user performing the action
     * @param Plugin $plugin The plugin being updated
     * @return bool True if the user can update the plugin
     */
    public function update(User $user, Plugin $plugin): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_PLUGINS->value);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user The user performing the action
     * @param Plugin $plugin The plugin being deleted
     * @return bool True if the user can delete the plugin
     */
    public function delete(User $user, Plugin $plugin): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_PLUGINS->value);
    }

    /**
     * Determine whether the user can activate/deactivate plugins.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can manage plugins
     */
    public function manage(User $user): bool
    {
        return $this->hasPermission($user, Permission::MANAGE_PLUGINS->value);
    }
}
