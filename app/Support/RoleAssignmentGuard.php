<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\CheckApiPermission;
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
 * An administrator acting through a personal access token scoped to specific
 * abilities is held to the same rules, with "holds" meaning their roles grant
 * the permission AND the token allows it.
 *
 * Shared by the user form (which aborts with 403) and the user CSV import
 * (which reports the violation as a row error), so both enforce exactly the
 * same rules.
 */
final class RoleAssignmentGuard
{
    public const SCOPED_TOKEN_MESSAGE = 'This API token is scoped; a full-access token is required to manage administrators or managers.';

    public const SCOPED_PERMISSION_MESSAGE = 'This API token is scoped; it cannot grant or manage permissions it does not include.';

    /**
     * The reason the actor may not assign these roles, or null when allowed.
     *
     * @param  array<int|string>  $roleIds
     */
    public static function violation(array $roleIds, User $actor, ?string $baseRole = null): ?string
    {
        // Tenant ownership is mandatory even for organization administrators.
        $roles = Role::whereIn('id', $roleIds)->get();
        foreach ($roles as $role) {
            if ($role->organization_id !== null && $role->organization_id !== $actor->organization_id) {
                return 'You cannot assign a role from another organization.';
            }
        }

        if (self::isUnrestrictedAdmin($actor)) {
            return null;
        }

        // Past this point an administrator is acting through a scoped token.
        $scopedAdmin = $actor->isAdmin();

        // isAdmin() is derived from the base `role` column (role === 'admin'),
        // so the base role is the primary escalation vector. A non-admin may
        // only assign the plain `member` base role (never admin/manager), and
        // this MUST be checked even when no custom role_ids are supplied: an
        // early return on empty role_ids once let a delegated user-manager
        // mint a full admin with `role: admin` and no role_ids.
        if ($baseRole !== null && $baseRole !== 'member') {
            return $scopedAdmin ? self::SCOPED_TOKEN_MESSAGE : 'You do not have permission to assign this role.';
        }

        foreach ($roles as $role) {
            if (in_array($role->slug, ['system-administrator', 'system-manager'], true)) {
                return $scopedAdmin ? self::SCOPED_TOKEN_MESSAGE : 'You do not have permission to assign this role.';
            }

            foreach ($role->getAllPermissions() as $permission) {
                if (! self::actorHolds($actor, $permission)) {
                    return $scopedAdmin
                        ? self::SCOPED_PERMISSION_MESSAGE
                        : 'You cannot assign a role with more permissions than you hold.';
                }
            }
        }

        return null;
    }

    /**
     * Whether the actor is an administrator acting with their full authority.
     *
     * A request authenticated by a personal access token that declares a
     * specific ability set is limited to those abilities, so an administrator
     * acting through it is treated like any other delegated user: route
     * middleware checks only the route's own permission, and without this a
     * narrowly scoped token could mint or take over an administrator.
     * Session auth, a token with no declared scope, a `*` token and a token
     * declaring every permission stay unrestricted (see
     * CheckApiPermission::grantsFullAccess()).
     */
    private static function isUnrestrictedAdmin(User $actor): bool
    {
        return $actor->isAdmin()
            && CheckApiPermission::grantsFullAccess($actor->currentAccessToken());
    }

    /**
     * Whether the actor holds the permission through their roles AND the
     * acting token allows it.
     */
    private static function actorHolds(User $actor, string $permission): bool
    {
        return $actor->hasPermission($permission)
            && CheckApiPermission::tokenAllows($actor->currentAccessToken())($permission);
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

        $fullAccessToken = CheckApiPermission::grantsFullAccess($actor->currentAccessToken());

        if (! self::isUnrestrictedAdmin($actor)) {
            // An administrator here is acting through a scoped token.
            $scopedAdmin = $actor->isAdmin();

            foreach (array_merge([$target], array_values($outside)) as $member) {
                if ($member->isAdmin()) {
                    return $scopedAdmin ? self::SCOPED_TOKEN_MESSAGE : 'You do not have permission to manage an administrator.';
                }

                if ($member->role === 'manager' || $member->hasRole('system-manager')) {
                    return $scopedAdmin ? self::SCOPED_TOKEN_MESSAGE : 'You do not have permission to manage a manager.';
                }

                foreach ($member->getAllPermissions() as $permission) {
                    if (! self::actorHolds($actor, $permission)) {
                        return $scopedAdmin
                            ? self::SCOPED_PERMISSION_MESSAGE
                            : 'You cannot manage a user who holds permissions you do not.';
                    }
                }
            }
        }

        if ($changesAccount) {
            foreach (array_keys($outside) as $organizationId) {
                $actorThere = self::memberIn($actor, $organizationId);

                if ($actorThere !== null && $actorThere->isAdmin() && ! $fullAccessToken) {
                    return self::SCOPED_TOKEN_MESSAGE;
                }

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
