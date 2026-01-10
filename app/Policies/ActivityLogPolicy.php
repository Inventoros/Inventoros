<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_ACTIVITY_LOG->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ActivityLog $activityLog): bool
    {
        return $this->belongsToSameOrganization($user, $activityLog)
            && $this->hasPermission($user, Permission::VIEW_ACTIVITY_LOG->value);
    }
}
