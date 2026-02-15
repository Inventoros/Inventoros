<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\User;

/**
 * Policy for authorization checks on ActivityLog model operations.
 *
 * Handles permissions for viewing activity logs within an organization.
 */
class ActivityLogPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * @param User $user The user performing the action
     * @return bool True if the user can view any activity logs
     */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, Permission::VIEW_ACTIVITY_LOG->value);
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user The user performing the action
     * @param ActivityLog $activityLog The activity log being viewed
     * @return bool True if the user can view the activity log
     */
    public function view(User $user, ActivityLog $activityLog): bool
    {
        return $this->belongsToSameOrganization($user, $activityLog)
            && $this->hasPermission($user, Permission::VIEW_ACTIVITY_LOG->value);
    }
}
