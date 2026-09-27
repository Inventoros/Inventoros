<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Prevents privilege escalation through role assignment.
 *
 * `roles()->sync()` bypasses the model-level self-role guard, so a non-admin
 * actor could otherwise attach the system-administrator role to themselves or
 * another user. A non-admin may only assign roles that (a) belong to their own
 * organization (or are system roles), (b) are not admin/manager-conferring,
 * and (c) carry no permission the actor does not already hold.
 *
 * Shared by the user form (which aborts with 403) and the user CSV import
 * (which reports the violation as a row error), so both enforce exactly the
 * same rules.
 */
final class RoleAssignmentGuard
{
    /**
     * The reason the actor may not assign these roles, or null when allowed.
     *
     * @param  array<int|string>  $roleIds
     */
    public static function violation(array $roleIds, User $actor, ?string $baseRole = null): ?string
    {
        if ($actor->isAdmin()) {
            return null;
        }

        // isAdmin() is derived from the base `role` column (role === 'admin'),
        // so the base role is the primary escalation vector. A non-admin may
        // only assign the plain `member` base role (never admin/manager), and
        // this MUST be checked even when no custom role_ids are supplied: an
        // early return on empty role_ids once let a delegated user-manager
        // mint a full admin with `role: admin` and no role_ids.
        if ($baseRole !== null && $baseRole !== 'member') {
            return 'You do not have permission to assign this role.';
        }

        foreach (Role::whereIn('id', $roleIds)->get() as $role) {
            if ($role->organization_id !== null && $role->organization_id !== $actor->organization_id) {
                return 'You cannot assign a role from another organization.';
            }

            if (in_array($role->slug, ['system-administrator', 'system-manager'], true)) {
                return 'You do not have permission to assign this role.';
            }

            foreach ($role->getAllPermissions() as $permission) {
                if (! $actor->hasPermission($permission)) {
                    return 'You cannot assign a role with more permissions than you hold.';
                }
            }
        }

        return null;
    }

    /**
     * Abort with 403 when the actor may not assign these roles.
     *
     * @param  array<int|string>  $roleIds
     */
    public static function authorize(array $roleIds, User $actor, ?string $baseRole = null): void
    {
        $violation = self::violation($roleIds, $actor, $baseRole);

        if ($violation !== null) {
            abort(403, $violation);
        }
    }
}
