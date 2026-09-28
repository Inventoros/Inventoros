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
     * The reason the actor may not manage (edit, reset the credentials of,
     * or delete) this existing user, or null when allowed.
     *
     * Checking only the roles being assigned is not enough: a delegated user
     * administrator could otherwise set a new password or email on an admin
     * and sign in as them. A non-admin may only manage users who hold no
     * more privilege than they do: never an admin or manager (base role or
     * system role), and never someone with a permission the actor lacks.
     */
    public static function targetViolation(User $target, User $actor): ?string
    {
        if ($actor->isAdmin()) {
            return null;
        }

        if ($target->isAdmin()) {
            return 'You do not have permission to manage an administrator.';
        }

        if ($target->role === 'manager' || $target->hasRole('system-manager')) {
            return 'You do not have permission to manage a manager.';
        }

        foreach ($target->getAllPermissions() as $permission) {
            if (! $actor->hasPermission($permission)) {
                return 'You cannot manage a user who holds permissions you do not.';
            }
        }

        return null;
    }

    /**
     * Abort with 403 when the actor may not manage this existing user.
     */
    public static function authorizeTarget(User $target, User $actor): void
    {
        $violation = self::targetViolation($target, $actor);

        if ($violation !== null) {
            abort(403, $violation);
        }
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
