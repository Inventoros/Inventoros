<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\SecurityEvent;
use App\Models\Auth\Organization;
use App\Models\Auth\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\SecurityEventLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who belongs to which organization, and with which base role.
 *
 * A user always belongs to their home organization (`users.organization_id`,
 * role `users.role`), which owns the account: its administrators edit and
 * delete it through the ordinary user screens. Further memberships let the
 * same account work in other organizations through the organization
 * switcher or an API token bound to that organization. They are granted and
 * withdrawn here, never by writing organization_user directly, so every
 * change is checked, cleaned up and recorded in both the security log and
 * the `organization_member_*` hooks.
 *
 * The service does not decide WHO may add members: callers (the
 * multi-company plugin, an artisan command) check that the acting user may
 * manage the target organization first.
 *
 * @api
 */
final class OrganizationMembershipService
{
    /** Base roles a membership can carry, as `users.role`. */
    public const ROLES = ['admin', 'manager', 'member'];

    public function __construct(private readonly SecurityEventLogger $security) {}

    /**
     * Whether $user may work in $organizationId: their home organization or
     * another they hold a membership of, either way active and not deleted.
     *
     * @api
     */
    public function isMember(User $user, int $organizationId): bool
    {
        return $this->resolve($user, $organizationId) !== null;
    }

    /**
     * The base role $user holds in $organizationId, or null when they are
     * not a member (or hold no base role there).
     *
     * @api
     */
    public function roleIn(User $user, int $organizationId): ?string
    {
        return $this->resolve($user, $organizationId)['role'] ?? null;
    }

    /**
     * The membership $user would work under in $organizationId, or null.
     *
     * @return array{organization_id: int, role: string|null}|null
     */
    public function resolve(User $user, int $organizationId): ?array
    {
        if ($organizationId === $user->homeOrganizationId()) {
            // A disabled (or deleted) home organization is worked in by nobody.
            return Organization::query()->whereKey($organizationId)->where('is_active', true)->exists()
                ? ['organization_id' => $organizationId, 'role' => $user->homeRole()]
                : null;
        }

        $membership = OrganizationMembership::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organizationId)
            ->whereHas('organization', fn (Builder $q) => $q->where('is_active', true))
            ->first();

        return $membership === null ? null : ['organization_id' => $organizationId, 'role' => $membership->role];
    }

    /**
     * The organizations $user can switch to, home first, then by name.
     *
     * @return Collection<int, Organization>
     *
     * @api
     */
    public function organizationsFor(User $user): Collection
    {
        $home = $user->homeOrganizationId();

        $organizations = Organization::query()
            ->where('is_active', true)
            ->where(function (Builder $q) use ($user, $home): void {
                $q->whereIn('id', OrganizationMembership::query()->select('organization_id')->where('user_id', $user->getKey()));

                if ($home !== null) {
                    $q->orWhere('id', $home);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return $organizations->sortBy(fn (Organization $o) => $o->id === $home ? 0 : 1)->values();
    }

    /**
     * Everyone who can work in the organization: its home users and the
     * members added from other organizations.
     *
     * @return Builder<User>
     *
     * @api
     */
    public function members(Organization|int $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        return User::query()->where(function (Builder $q) use ($organizationId): void {
            $q->where('users.organization_id', $organizationId)
                ->orWhereIn('users.id', OrganizationMembership::query()->select('user_id')->where('organization_id', $organizationId));
        });
    }

    /**
     * Give $user access to $organization with a base role there. Adding an
     * existing member changes their role instead. The home organization's
     * role is users.role, changed through the user screens.
     *
     * @throws ValidationException for an unknown role, the user's home organization, or a deleted or inactive organization
     *
     * @api
     */
    public function add(Organization $organization, User $user, string $role = 'member', ?User $actor = null): OrganizationMembership
    {
        $this->assertRole($role);
        $this->assertNotHome($organization, $user);

        if ($organization->trashed() || ! $organization->is_active) {
            throw ValidationException::withMessages(['organization' => 'Members cannot be added to an inactive organization.']);
        }

        $existing = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing !== null) {
            return $this->changeRole($organization, $user, $role, $actor);
        }

        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        $this->security->record(SecurityEvent::MEMBERSHIP_ADDED, $user, $actor, [
            'organization_id' => (int) $organization->getKey(),
            'role' => $role,
        ], organizationId: (int) $organization->getKey());

        DB::afterCommit(fn () => do_action('organization_member_added', $membership, $actor));

        return $membership;
    }

    /**
     * Change the base role of a member who joined from another organization.
     *
     * @throws ValidationException for an unknown role, the home organization, a non-member, or the organization's last administrator
     *
     * @api
     */
    public function changeRole(Organization $organization, User $user, string $role, ?User $actor = null): OrganizationMembership
    {
        $this->assertRole($role);
        $this->assertNotHome($organization, $user);

        return DB::transaction(function () use ($organization, $user, $role, $actor) {
            $membership = $this->lockedMembership($organization, $user);
            $previous = $membership->role;

            if ($previous === $role) {
                return $membership;
            }

            if ($previous === 'admin') {
                $this->assertNotLastAdmin($organization, $user);
            }

            $membership->update(['role' => $role]);

            $this->security->record(SecurityEvent::MEMBERSHIP_ROLE_CHANGED, $user, $actor, [
                'organization_id' => (int) $organization->getKey(),
                'from' => $previous,
                'to' => $role,
            ], organizationId: (int) $organization->getKey());

            return $membership;
        });
    }

    /**
     * Withdraw a membership. Everything the user held in that organization
     * goes with it: their roles and warehouse assignments there, and the API
     * tokens bound to it. Their account, home organization and other
     * memberships are untouched; their next request in that organization
     * falls back to their home organization.
     *
     * @throws ValidationException for the home organization, a non-member, or the organization's last administrator
     *
     * @api
     */
    public function remove(Organization $organization, User $user, ?User $actor = null): void
    {
        $this->assertNotHome($organization, $user);
        $organizationId = (int) $organization->getKey();

        DB::transaction(function () use ($organization, $user, $actor, $organizationId): void {
            $membership = $this->lockedMembership($organization, $user);

            if ($membership->role === 'admin') {
                $this->assertNotLastAdmin($organization, $user);
            }

            $membership->delete();

            DB::table('role_user')
                ->where('user_id', $user->getKey())
                ->where('organization_id', $organizationId)
                ->delete();

            DB::table('warehouse_user')
                ->where('user_id', $user->getKey())
                ->whereIn('warehouse_id', DB::table('warehouses')->select('id')->where('organization_id', $organizationId))
                ->delete();

            PersonalAccessToken::query()
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->getKey())
                ->where('organization_id', $organizationId)
                ->delete();

            $this->security->record(SecurityEvent::MEMBERSHIP_REMOVED, $user, $actor, [
                'organization_id' => $organizationId,
                'role' => $membership->role,
            ], organizationId: $organizationId);

            DB::afterCommit(fn () => do_action('organization_member_removed', $organizationId, $user, $actor));
        });
    }

    /**
     * Create an organization with $admin as one of its administrators (a
     * membership: $admin keeps their own home organization).
     *
     * @param  array<string, mixed>  $attributes  Organization attributes (name required)
     *
     * @api
     */
    public function createOrganization(array $attributes, User $admin, ?User $actor = null): Organization
    {
        return DB::transaction(function () use ($attributes, $admin, $actor) {
            $organization = Organization::query()->create(['is_active' => true] + $attributes);

            $this->add($organization, $admin, 'admin', $actor ?? $admin);

            return $organization;
        });
    }

    /**
     * Every organization the user holds a membership row for (home
     * included), with the base role there, whether or not the organization
     * is active; deleted organizations are left out. For checks that must
     * consider access the user could regain, not only what they can use now.
     *
     * @return array<int, string|null>
     */
    public function allMemberships(User $user): array
    {
        return OrganizationMembership::query()
            ->where('user_id', $user->getKey())
            ->whereHas('organization')
            ->pluck('role', 'organization_id')
            ->mapWithKeys(fn ($role, $organizationId) => [(int) $organizationId => $role])
            ->all();
    }

    /**
     * Whether $user is the only administrator of the organization.
     */
    public function isLastAdministrator(Organization|int $organization, User $user): bool
    {
        $organization = $organization instanceof Organization ? $organization : Organization::withTrashed()->findOrFail($organization);

        try {
            $this->assertNotLastAdmin($organization, $user);

            return false;
        } catch (ValidationException) {
            return true;
        }
    }

    private function lockedMembership(Organization $organization, User $user): OrganizationMembership
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages(['user' => 'This user is not a member of the organization.']);
        }

        return $membership;
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'The role must be admin, manager or member.']);
        }
    }

    private function assertNotHome(Organization $organization, User $user): void
    {
        if ((int) $organization->getKey() === $user->homeOrganizationId()) {
            throw ValidationException::withMessages([
                'organization' => 'This is the user\'s home organization; change their role or account through the user screens.',
            ]);
        }
    }

    private function assertNotLastAdmin(Organization $organization, User $user): void
    {
        $otherAdmins = $this->members($organization)
            ->whereKeyNot($user->getKey())
            ->where(function (Builder $q) use ($organization): void {
                $q->where(fn (Builder $home) => $home->where('users.organization_id', $organization->getKey())->where('users.role', 'admin'))
                    ->orWhereIn('users.id', OrganizationMembership::query()->select('user_id')
                        ->where('organization_id', $organization->getKey())
                        ->where('role', 'admin'));
            })
            ->exists();

        if (! $otherAdmins) {
            throw ValidationException::withMessages(['role' => 'Cannot remove the last administrator of the organization.']);
        }
    }
}
