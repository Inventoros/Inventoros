<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Updates and backups affect the entire installation. Use the same owner
 * organization as plugin administration, while retaining the updater's
 * existing base-admin and manage_organization requirements.
 */
final class UpdateAdministration
{
    public static function canManage(?User $user): bool
    {
        return $user !== null
            && $user->is_admin
            && $user->hasPermission('manage_organization')
            && PluginAdministration::isAdministratorOrganization($user);
    }
}
