<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class StorageAdministration
{
    public static function canManage(?User $user): bool
    {
        // Storage configuration and connection probes affect every tenant.
        return $user !== null
            && $user->is_admin
            && $user->hasPermission('manage_organization')
            && PluginAdministration::isAdministratorOrganization($user);
    }
}
