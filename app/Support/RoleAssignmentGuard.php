<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\OrganizationMembershipService;

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
     * more privilege than they do, in ANY organization the user belongs to:
     * never an admin or manager (base role or system role), and never someone
     * with a permission the actor lacks.
     *
     * The home organization owns the account, but an account that also works
     * in other organizations carries their access: changing its email or
     * password, or deleting it ($changesAccount), needs an administrator of
     * every one of those organizations, so no organization's administrators
     * can take over (or remove) access to another they do not run.
     */
    public static function targetViolation(User $target, User $actor, bool $changesAccount = true): ?string
    {
        $outside = self::outsideMembers($target, $actor->organization_id === null ? null : (int) $actor->organization_id);

        if (! $actor->isAdmin()) {
            foreach (array_merge([$target], array_values($outside)) as $member) {
                if ($member->isAdmin()) {
                    return 'You do not have permission to manage an administrator.';
                }

                if ($member->role === 'manager' || $member->hasRole('system-manager')) {
                    return 'You do not have permission to manage a manager.';
                }

                foreach ($member->getAllPermissions() as $permission) {
                    if (! $actor->hasPermission($permission)) {
                        return 'You cannot manage a user who holds permissions you do not.';
                    }
                }
            }
        }

        if ($changesAccount) {
            foreach (array_keys($outside) as $organizationId) {
                $actorThere = self::memberIn($actor, $organizationId);

                if ($actorThere === null || ! $actorThere->isAdmin()) {
                    return 'This user also works in other organizations. Only an administrator of each of them can change their email or password or delete the account.';
                }
            }
        }

        return null;
    }

    /**
     * The reason the actor may not delete this user, or null when allowed:
     * the management rules above, and never the last administrator of
     * another organization the user belongs to.
     */
    public static function deletionViolation(User $target, User $actor): ?string
    {
        if (($violation = self::targetViolation($target, $actor)) !== null) {
            return $violation;
        }

        $memberships = app(OrganizationMembershipService::class);

        foreach (self::outsideMembers($target, $actor->organization_id === null ? null : (int) $actor->organization_id) as $organizationId => $member) {
            if ($member->role === 'admin' && $memberships->isLastAdministrator($organizationId, $target)) {
                return 'This user is the last administrator of another organization.';
            }
        }

        return null;
    }

    /**
     * Copies of $user working in each organization they belong to besides
     * $except and their home, whether or not that organization is active.
     *
     * @return array<int, User>
     */
    private static function outsideMembers(User $user, ?int $except): array
    {
        $members = [];

        foreach (app(OrganizationMembershipService::class)->allMemberships($user) as $organizationId => $role) {
            if ($organizationId !== $except && $organizationId !== $user->homeOrganizationId()) {
                $members[$organizationId] = (clone $user)->useOrganization($organizationId, $role);
            }
        }

        return $members;
    }

    private static function memberIn(User $user, int $organizationId): ?User
    {
        if ($organizationId === $user->homeOrganizationId()) {
            return (clone $user)->useOrganization($organizationId, $user->homeRole());
        }

        $memberships = app(OrganizationMembershipService::class)->allMemberships($user);

        return array_key_exists($organizationId, $memberships)
            ? (clone $user)->useOrganization($organizationId, $memberships[$organizationId])
            : null;
    }

    /**
     * Abort with 403 when the actor may not delete this user.
     */
    public static function authorizeDeletion(User $target, User $actor): void
    {
        $violation = self::deletionViolation($target, $actor);

        if ($violation !== null) {
            abort(403, $violation);
        }
    }

    /**
     * Abort with 403 when the actor may not manage this existing user.
     */
    public static function authorizeTarget(User $target, User $actor, bool $changesAccount = true): void
    {
        $violation = self::targetViolation($target, $actor, $changesAccount);

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
