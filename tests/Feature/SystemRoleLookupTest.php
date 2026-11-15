<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\UserActivityAlertEmail;
use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\UserActivityAlertService;
use App\Services\UserManagementService;
use App\Support\RoleAssignmentGuard;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The base roles (admin, manager, member) take their permissions from the
 * built-in system roles, which are found by slug. An organization's own role
 * can end up with the same slug (when the system role row does not exist yet,
 * for example on an install that never ran RoleSeeder), and must then never
 * act as the system role: not for its own organization, and above all not
 * for every other organization's members.
 */
class SystemRoleLookupTest extends TestCase
{
    use RefreshDatabase;

    private Organization $first;

    private Organization $second;

    private User $firstAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->first = Organization::create(['name' => 'First', 'email' => 'first@org.test']);
        $this->second = Organization::create(['name' => 'Second', 'email' => 'second@org.test']);
        $this->firstAdmin = User::factory()->admin()->forOrganization($this->first->id)->create();
    }

    /** A role the first organization's admin creates through the role form. */
    private function tenantRole(string $name, array $permissions): Role
    {
        $this->actingAs($this->firstAdmin)
            ->post(route('roles.store'), ['name' => $name, 'permissions' => $permissions])
            ->assertRedirect(route('roles.index'));

        return Role::where('organization_id', $this->first->id)->where('name', $name)->firstOrFail();
    }

    private function userWithBaseRole(Organization $organization, string $role): User
    {
        return User::factory()->forOrganization($organization->id)->create(['role' => $role]);
    }

    public function test_a_tenant_role_named_system_member_grants_nothing_to_members(): void
    {
        $role = $this->tenantRole('System Member', ['view_suppliers']);
        $this->assertSame('system-member', $role->slug);

        $foreignMember = $this->userWithBaseRole($this->second, 'member');
        $ownMember = $this->userWithBaseRole($this->first, 'member');

        foreach ([$foreignMember, $ownMember] as $member) {
            $this->assertFalse($member->hasPermission('view_suppliers'));
            $this->assertNotContains('view_suppliers', $member->getAllPermissions());
        }

        $this->actingAs($foreignMember)->get(route('suppliers.index'))->assertForbidden();
    }

    public function test_a_tenant_role_named_system_manager_grants_nothing_to_managers(): void
    {
        $role = $this->tenantRole('System Manager', ['view_suppliers']);
        $this->assertSame('system-manager', $role->slug);

        $foreignManager = $this->userWithBaseRole($this->second, 'manager');

        $this->assertFalse($foreignManager->hasPermission('view_suppliers'));
        $this->assertNotContains('view_suppliers', $foreignManager->getAllPermissions());
    }

    public function test_the_tenant_role_still_grants_its_permissions_to_the_users_it_is_assigned_to(): void
    {
        $role = $this->tenantRole('System Member', ['view_suppliers']);
        $holder = $this->userWithBaseRole($this->first, 'member');
        $holder->roles()->attach($role->id);

        $this->assertTrue($holder->fresh()->hasPermission('view_suppliers'));
    }

    public function test_real_system_roles_still_give_the_base_roles_their_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $member = $this->userWithBaseRole($this->second, 'member');
        $manager = $this->userWithBaseRole($this->second, 'manager');

        $this->assertTrue($member->hasPermission('view_products'));
        $this->assertContains('view_orders', $member->getAllPermissions());
        $this->assertFalse($member->hasPermission('view_suppliers'));
        $this->assertTrue($manager->hasPermission('approve_orders'));
        $this->assertContains('export_data', $manager->getAllPermissions());
    }

    public function test_role_seeder_does_not_adopt_a_tenant_role_holding_a_system_slug(): void
    {
        $tenant = $this->tenantRole('System Member', ['view_suppliers']);
        $holder = $this->userWithBaseRole($this->first, 'member');
        $holder->roles()->attach($tenant->id);

        $this->seed(RoleSeeder::class);

        // The tenant role is kept as it was, apart from giving up the slug.
        $tenant = $tenant->fresh();
        $this->assertSame($this->first->id, $tenant->organization_id);
        $this->assertFalse($tenant->is_system);
        $this->assertSame(['view_suppliers'], $tenant->permissions);
        $this->assertSame('System Member', $tenant->name);
        $this->assertNotSame('system-member', $tenant->slug);
        $this->assertTrue($holder->fresh()->hasPermission('view_suppliers'));

        // The real system role now exists and applies to every member.
        $system = Role::where('slug', 'system-member')->firstOrFail();
        $this->assertNotSame($tenant->id, $system->id);
        $this->assertTrue($system->is_system);
        $this->assertNull($system->organization_id);

        $foreignMember = $this->userWithBaseRole($this->second, 'member');
        $this->assertTrue($foreignMember->hasPermission('view_products'));
        $this->assertFalse($foreignMember->hasPermission('view_suppliers'));
    }

    public function test_role_seeder_updates_the_existing_system_roles_in_place(): void
    {
        $this->seed(RoleSeeder::class);
        $ids = Role::where('is_system', true)->orderBy('slug')->pluck('id', 'slug')->all();

        $this->seed(RoleSeeder::class);

        $this->assertSame($ids, Role::where('is_system', true)->orderBy('slug')->pluck('id', 'slug')->all());
        $this->assertCount(3, $ids);
    }

    public function test_the_assignment_guard_treats_only_the_real_system_manager_role_as_a_manager(): void
    {
        $tenant = $this->tenantRole('System Manager', ['view_products']);
        $actor = $this->userWithBaseRole($this->first, 'member');
        $actorRole = Role::create(['name' => 'User editor', 'organization_id' => $this->first->id,
            'is_system' => false, 'permissions' => ['view_users', 'edit_users', 'view_products']]);
        $actor->roles()->attach($actorRole->id);

        $target = $this->userWithBaseRole($this->first, 'member');
        $target->roles()->attach($tenant->id);

        $this->assertNull(RoleAssignmentGuard::targetViolation($target->fresh(), $actor->fresh()));

        $this->seed(RoleSeeder::class);
        $manager = $this->userWithBaseRole($this->first, 'member');
        $manager->roles()->attach(Role::where('slug', 'system-manager')->value('id'));

        $this->assertSame(
            'You do not have permission to manage a manager.',
            RoleAssignmentGuard::targetViolation($manager->fresh(), $actor->fresh()),
        );
    }

    public function test_assigning_a_tenant_role_slugged_like_the_administrator_role_is_not_a_promotion(): void
    {
        $tenant = $this->tenantRole('System Administrator', ['view_products']);
        $this->assertSame('system-administrator', $tenant->slug);

        User::factory()->admin()->forOrganization($this->first->id)->create([
            'email' => 'watcher@example.com',
            'notification_preferences' => ['user_activity_alerts' => true],
        ]);
        $member = $this->userWithBaseRole($this->first, 'member');

        Mail::fake();
        app(UserManagementService::class)->update($this->firstAdmin, $member, [
            'name' => $member->name,
            'email' => $member->email,
            'role' => 'member',
            'role_ids' => [$tenant->id],
        ]);

        $this->assertFalse($member->fresh()->isAdmin());
        Mail::assertNotQueued(UserActivityAlertEmail::class, fn (UserActivityAlertEmail $mail) => $mail->data['type'] === UserActivityAlertService::TYPE_PROMOTED_TO_ADMIN);
    }
}
