<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Auth\Organization;
use App\Models\User;

/**
 * Who may change plugins on this installation.
 *
 * Plugins are installed for the whole installation: their files live in
 * /plugins, the `plugins` table has no organization, and an active plugin
 * runs PHP for every tenant. The manage_plugins permission, however, is
 * granted per organization. So on an installation with more than one
 * organization, plugin changes are limited to ONE organization, the plugin
 * administrator organization:
 *
 *  - INVENTOROS_PLUGIN_ADMIN_ORG (config plugins.admin_organization_id) when
 *    set, otherwise
 *  - the first organization created (the one the installer set up).
 *
 * Its users still need manage_plugins. Everyone else can browse plugins but
 * not install, update, activate, deactivate, upload or delete them.
 */
final class PluginAdministration
{
    public static function organizationId(): ?int
    {
        $configured = config('plugins.admin_organization_id');

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $first = Organization::query()->withoutGlobalScopes()->min('id');

        return $first === null ? null : (int) $first;
    }

    /**
     * Whether the user belongs to the plugin administrator organization.
     * The manage_plugins permission is checked separately.
     */
    public static function isAdministratorOrganization(?User $user): bool
    {
        $organizationId = self::organizationId();

        return $user !== null
            && $organizationId !== null
            && $user->organization_id !== null
            && (int) $user->organization_id === $organizationId;
    }
}
