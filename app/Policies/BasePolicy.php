<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class BasePolicy
{
    /**
     * Check if the user belongs to the same organization as the model.
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
     */
    protected function hasPermission(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    /**
     * Deny access with a standard message.
     */
    protected function denyWithMessage(string $message = 'Unauthorized action.'): bool
    {
        return false;
    }
}
