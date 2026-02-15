<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy class providing common authorization utilities.
 *
 * This abstract class provides shared methods for checking organization
 * membership and user permissions that can be used by all policy classes.
 */
abstract class BasePolicy
{
    /**
     * Check if the user belongs to the same organization as the model.
     *
     * @param User $user The user performing the action
     * @param Model $model The model being accessed
     * @return bool True if the user and model belong to the same organization
     */
    protected function belongsToSameOrganization(User $user, Model $model): bool
    {
        if (!property_exists($model, 'organization_id') && !isset($model->organization_id)) {
            return true;
        }

        return $user->organization_id === $model->organization_id;
    }

    /**
     * Check if user has a specific permission.
     *
     * @param User $user The user to check permissions for
     * @param string $permission The permission to verify
     * @return bool True if the user has the specified permission
     */
    protected function hasPermission(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    /**
     * Deny access with a standard message.
     *
     * @param string $message The denial message (unused but kept for API consistency)
     * @return bool Always returns false
     */
    protected function denyWithMessage(string $message = 'Unauthorized action.'): bool
    {
        return false;
    }
}
