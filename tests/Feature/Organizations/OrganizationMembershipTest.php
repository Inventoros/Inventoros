<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\SecurityEvent;
use App\Models\ActivityLog;
use App\Models\Auth\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Organizations\ActiveOrganization;
use App\Services\Organizations\OrganizationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * Organization memberships: the home membership mirrors users.organization_id
 * and users.role, further memberships are granted and withdrawn only through
 * OrganizationMembershipService, and an instance switched into another
 * organization can never write that organization onto the account.
 */
final class OrganizationMembershipTest extends TestCase
{
    use BuildsOrganizations, RefreshDatabase;

    private function memberships(): OrganizationMembershipService
    {
        return app(OrganizationMembershipService::class);
    }

    public function test_a_new_user_is_a_member_of_their_home_organization(): void
    {
        $a = $this->organization('Alpha');
        $user = $this->homeUser($a, 'manager');

        $this->assertDatabaseHas('organization_user', ['organization_id' => $a->id, 'user_id' => $user->id, 'role' => 'manager']);
        $this->assertTrue($this->memberships()->isMember($user, $a->id));
        $this->assertSame('manager', $this->memberships()->roleIn($user, $a->id));
    }

    public function test_the_home_membership_follows_role_and_organization_changes(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $user = $this->homeUser($a, 'member');

        $user->update(['role' => 'manager']);
        $this->assertDatabaseHas('organization_user', ['organization_id' => $a->id, 'user_id' => $user->id, 'role' => 'manager']);

        // Moving the account to another home organization moves it entirely.
        $user->update(['organization_id' => $b->id]);
        $this->assertDatabaseMissing('organization_user', ['organization_id' => $a->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('organization_user', ['organization_id' => $b->id, 'user_id' => $user->id, 'role' => 'manager']);
    }

    public function test_a_user_who_belongs_to_one_organization_lists_only_it(): void
    {
        $a = $this->organization('Alpha');
        $this->organization('Beta');
        $user = $this->homeUser($a);

        $this->assertSame([$a->id], $this->memberships()->organizationsFor($user)->pluck('id')->all());
    }

    public function test_adding_a_membership_lists_the_organization_home_first(): void
    {
        $a = $this->organization('Zulu');
        $b = $this->organization('Alpha');
        $user = $this->homeUser($a);

        $this->addMember($b, $user, 'manager');

        $this->assertSame([$a->id, $b->id], $this->memberships()->organizationsFor($user)->pluck('id')->all());
        $this->assertSame('manager', $this->memberships()->roleIn($user, $b->id));
        $this->assertSame(1, ActivityLog::where('action', SecurityEvent::MEMBERSHIP_ADDED->value)->where('organization_id', $b->id)->count());
    }

    public function test_memberships_of_inactive_or_deleted_organizations_do_not_count(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $c = $this->organization('Gamma');
        $user = $this->homeUser($a);
        $this->addMember($b, $user);
        $this->addMember($c, $user);

        $b->update(['is_active' => false]);
        $c->delete();

        $this->assertFalse($this->memberships()->isMember($user, $b->id));
        $this->assertFalse($this->memberships()->isMember($user, $c->id));
        $this->assertSame([$a->id], $this->memberships()->organizationsFor($user)->pluck('id')->all());
    }

    public function test_the_service_refuses_the_home_organization_unknown_roles_and_inactive_organizations(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $user = $this->homeUser($a);

        foreach ([
            fn () => $this->memberships()->add($a, $user, 'member'),
            fn () => $this->memberships()->remove($a, $user),
            fn () => $this->memberships()->add($b, $user, 'owner'),
            function () use ($b, $user) {
                $b->update(['is_active' => false]);
                $this->memberships()->add($b, $user);
            },
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertSame(1, OrganizationMembership::where('user_id', $user->id)->count());
    }

    public function test_removing_a_membership_takes_its_roles_warehouses_and_tokens_with_it(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $user = $this->homeUser($a);
        $this->homeUser($b, 'admin', 'Owner');
        $this->addMember($b, $user, 'manager');

        $roleA = $this->grantInOrganization($user, $a, ['view_products']);
        $roleB = $this->grantInOrganization($user, $b, ['view_products']);
        $warehouseA = Warehouse::factory()->create(['organization_id' => $a->id]);
        $warehouseB = Warehouse::factory()->create(['organization_id' => $b->id]);
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

        $member = app(ActiveOrganization::class)->userIn($user, $b->id);
        $member->createToken('beta');
        $user->createToken('alpha');

        $this->memberships()->remove($b, $user);

        $this->assertFalse($this->memberships()->isMember($user, $b->id));
        $this->assertDatabaseHas('role_user', ['role_id' => $roleA->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('role_user', ['role_id' => $roleB->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('warehouse_user', ['warehouse_id' => $warehouseA->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('warehouse_user', ['warehouse_id' => $warehouseB->id, 'user_id' => $user->id]);
        $this->assertSame(['alpha'], PersonalAccessToken::where('tokenable_id', $user->id)->pluck('name')->all());
        $this->assertSame(1, ActivityLog::where('action', SecurityEvent::MEMBERSHIP_REMOVED->value)->where('organization_id', $b->id)->count());
    }

    public function test_the_last_administrator_of_an_organization_cannot_be_demoted_or_removed(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->memberships()->createOrganization(['name' => 'Beta'], $admin = $this->homeUser($a));

        $this->assertSame('admin', $this->memberships()->roleIn($admin, $b->id));

        foreach ([fn () => $this->memberships()->changeRole($b, $admin, 'member'), fn () => $this->memberships()->remove($b, $admin)] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
                // expected
            }
        }

        $second = $this->homeUser($a, 'member', 'Sam');
        $this->addMember($b, $second, 'admin');
        $this->memberships()->changeRole($b, $admin, 'member');

        $this->assertSame('member', $this->memberships()->roleIn($admin, $b->id));
    }

    public function test_hooks_fire_after_commit_for_added_and_removed_members(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $this->homeUser($b, 'admin', 'Owner');
        $user = $this->homeUser($a);
        $seen = [];

        add_action('organization_member_added', function ($membership) use (&$seen) {
            $seen[] = 'added:'.$membership->organization_id;
        });
        add_action('organization_member_removed', function ($organizationId, $removed) use (&$seen) {
            $seen[] = 'removed:'.$organizationId.':'.$removed->id;
        });

        $this->addMember($b, $user);
        $this->memberships()->remove($b, $user);

        $this->assertSame(['added:'.$b->id, 'removed:'.$b->id.':'.$user->id], $seen);
    }

    public function test_members_lists_home_users_and_added_members_only(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $home = $this->homeUser($b, 'admin', 'Home');
        $guest = $this->homeUser($a, 'admin', 'Guest');
        $outsider = $this->homeUser($a, 'admin', 'Outsider');
        $this->addMember($b, $guest);

        $ids = $this->memberships()->members($b)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$home->id, $guest->id], $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_a_switched_instance_keeps_the_home_values_and_cannot_write_them(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $user = $this->homeUser($a, 'admin');
        $this->addMember($b, $user, 'member');

        $member = app(ActiveOrganization::class)->userIn($user, $b->id);

        $this->assertSame($b->id, $member->organization_id);
        $this->assertSame('member', $member->role);
        $this->assertSame($a->id, $member->homeOrganizationId());
        $this->assertSame('admin', $member->homeRole());
        $this->assertTrue($member->isInGuestOrganization());
        $this->assertSame($a->id, $user->organization_id, 'userIn() works on a copy.');

        // Saving other changes does not move the account.
        $member->update(['name' => 'Renamed']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Renamed', 'organization_id' => $a->id, 'role' => 'admin']);

        // refresh() reloads without leaving the organization.
        $member->refresh();
        $this->assertSame($b->id, $member->organization_id);
        $this->assertSame('member', $member->role);

        $this->expectException(\RuntimeException::class);
        $member->update(['role' => 'admin']);
    }

    public function test_userin_refuses_non_members(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $user = $this->homeUser($a);

        $this->assertNull(app(ActiveOrganization::class)->userIn($user, $b->id));
        $this->assertNull(app(ActiveOrganization::class)->userIn($user->id, $b->id));
        $this->assertNull(app(ActiveOrganization::class)->userIn(999999, $a->id));
    }

    public function test_roles_are_held_per_organization(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $user = $this->homeUser($a, 'member');
        $this->addMember($b, $user, 'member');
        $this->grantInOrganization($user, $a, ['delete_products']);

        // The Administrator system role, held in A only.
        $administrator = Role::firstOrCreate(['slug' => 'system-administrator'], ['name' => 'Administrator', 'is_system' => true, 'permissions' => []]);
        $user->roles()->attach($administrator->id);

        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->hasPermission('delete_products'));

        $inB = app(ActiveOrganization::class)->userIn($user, $b->id);
        $this->assertFalse($inB->isAdmin());
        $this->assertFalse($inB->hasPermission('delete_products'));
        $this->assertNotContains('delete_products', $inB->getAllPermissions());

        // Even after eager loading the home roles onto the instance.
        $inB->setRelation('roles', $user->roles()->get());
        $this->assertFalse($inB->hasPermission('delete_products'));

        // Roles attached while working in B belong to B.
        $inB->roles()->attach($administrator->id);
        $this->assertTrue(app(ActiveOrganization::class)->userIn($user->fresh(), $b->id)->isAdmin());
        $this->assertDatabaseHas('role_user', ['role_id' => $administrator->id, 'user_id' => $user->id, 'organization_id' => $b->id]);
    }

    public function test_a_role_assignment_always_names_one_organization_and_is_held_once(): void
    {
        $a = $this->organization('Alpha');
        $user = $this->homeUser($a);
        $role = $this->grantInOrganization($user, $a, ['view_products']);
        $row = ['role_id' => $role->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()];

        foreach ([$row + ['organization_id' => null], $row + ['organization_id' => null], $row + ['organization_id' => $a->id]] as $attempt) {
            try {
                // A savepoint, so PostgreSQL carries on after the refused insert.
                DB::transaction(fn () => DB::table('role_user')->insert($attempt));
            } catch (\Illuminate\Database\QueryException) {
                continue;
            }
        }

        $this->assertSame(1, DB::table('role_user')->where('role_id', $role->id)->where('user_id', $user->id)->count());
    }

    public function test_eager_loaded_roles_are_the_home_organization_roles(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $user = $this->homeUser($a, 'member');
        $this->addMember($b, $user);
        $roleA = $this->grantInOrganization($user, $a, ['view_products']);
        $this->grantInOrganization($user, $b, ['view_orders']);

        $loaded = User::with('roles')->whereKey($user->id)->first();

        $this->assertSame([$roleA->id], $loaded->roles->pluck('id')->all());
    }

    public function test_the_backfill_gives_every_existing_user_their_home_membership_and_binds_tokens(): void
    {
        $a = $this->organization('Alpha');
        $b = $this->organization('Beta');
        $user = $this->homeUser($a, 'manager');
        $second = $this->homeUser($b, 'member', 'Sam');
        $role = $this->grantInOrganization($user, $a, ['view_products']);
        $token = $user->createToken('legacy');

        // The state before the upgrade: no memberships and unbound tokens.
        // (Role assignments cannot be unbound in the new schema; the
        // migration test runs their backfill on the real column.)
        DB::table('organization_user')->delete();
        DB::table('personal_access_tokens')->update(['organization_id' => null]);

        foreach ([
            '2026_10_05_053128_create_organization_user_table.php',
            '2026_10_05_053130_add_organization_id_to_personal_access_tokens_table.php',
        ] as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->backfill();
            $migration->backfill(); // idempotent
        }

        $this->assertSame(2, DB::table('organization_user')->count());
        $this->assertDatabaseHas('organization_user', ['organization_id' => $a->id, 'user_id' => $user->id, 'role' => 'manager']);
        $this->assertDatabaseHas('organization_user', ['organization_id' => $b->id, 'user_id' => $second->id, 'role' => 'member']);
        $this->assertDatabaseHas('role_user', ['role_id' => $role->id, 'user_id' => $user->id, 'organization_id' => $a->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id, 'organization_id' => $a->id]);
    }
}
