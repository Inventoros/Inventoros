<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom role names are free text in each organization. A name another
 * organization (or a system role) already uses must neither fail role
 * creation nor make the role count as a system role.
 */
class RoleSlugCollisionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $first;

    private Organization $second;

    private User $firstAdmin;

    private User $secondAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->seed(RoleSeeder::class);

        $this->first = Organization::create(['name' => 'First', 'email' => 'first@org.test']);
        $this->second = Organization::create(['name' => 'Second', 'email' => 'second@org.test']);
        $this->firstAdmin = User::factory()->admin()->forOrganization($this->first->id)->create();
        $this->secondAdmin = User::factory()->admin()->forOrganization($this->second->id)->create();
    }

    public function test_two_organizations_can_each_create_a_role_with_the_same_name(): void
    {
        $this->actingAs($this->firstAdmin)
            ->post(route('roles.store'), ['name' => 'Manager', 'permissions' => ['view_products']])
            ->assertRedirect(route('roles.index'));

        $this->actingAs($this->secondAdmin)
            ->post(route('roles.store'), ['name' => 'Manager', 'permissions' => ['view_products']])
            ->assertRedirect(route('roles.index'));

        $this->assertSame(1, Role::where('organization_id', $this->first->id)->where('name', 'Manager')->count());
        $this->assertSame(1, Role::where('organization_id', $this->second->id)->where('name', 'Manager')->count());
    }

    public function test_renaming_a_role_to_a_name_used_elsewhere_does_not_fail(): void
    {
        $this->actingAs($this->firstAdmin)
            ->post(route('roles.store'), ['name' => 'Pickers', 'permissions' => ['view_products']]);

        $this->actingAs($this->secondAdmin)
            ->post(route('roles.store'), ['name' => 'Packers', 'permissions' => ['view_products']]);
        $role = Role::where('organization_id', $this->second->id)->where('name', 'Packers')->firstOrFail();

        $this->put(route('roles.update', $role), ['name' => 'Pickers', 'permissions' => ['view_products']])
            ->assertRedirect(route('roles.index'));
        $this->assertSame('Pickers', $role->fresh()->name);
    }

    public function test_a_role_named_after_a_system_role_can_be_created_and_grants_only_its_permissions(): void
    {
        $this->actingAs($this->firstAdmin)
            ->post(route('roles.store'), ['name' => 'System Administrator', 'permissions' => ['view_products']])
            ->assertRedirect(route('roles.index'));

        $role = Role::where('organization_id', $this->first->id)->where('name', 'System Administrator')->firstOrFail();
        $holder = User::factory()->forOrganization($this->first->id)->create(['role' => 'member']);
        $holder->roles()->attach($role->id);

        $holder = $holder->fresh();
        $this->assertFalse($holder->isAdmin());
        $this->assertFalse($holder->hasPermission('manage_organization'));
    }

    public function test_a_custom_role_named_manager_does_not_make_its_holders_managers(): void
    {
        $this->actingAs($this->firstAdmin)
            ->post(route('roles.store'), ['name' => 'Manager', 'permissions' => ['view_products']])
            ->assertRedirect(route('roles.index'));

        $role = Role::where('organization_id', $this->first->id)->where('name', 'Manager')->firstOrFail();
        $holder = User::factory()->forOrganization($this->first->id)->create(['role' => 'member']);
        $holder->roles()->attach($role->id);

        $this->assertFalse($holder->fresh()->isManager());
    }

    public function test_the_system_roles_still_identify_admins_and_managers(): void
    {
        $manager = User::factory()->forOrganization($this->first->id)->create(['role' => 'member']);
        $manager->roles()->attach(Role::where('slug', 'system-manager')->value('id'));
        $this->assertTrue($manager->fresh()->isManager());
        $this->assertFalse($manager->fresh()->isAdmin());

        $admin = User::factory()->forOrganization($this->first->id)->create(['role' => 'member']);
        $admin->roles()->attach(Role::where('slug', 'system-administrator')->value('id'));
        $this->assertTrue($admin->fresh()->isAdmin());
        $this->assertTrue($admin->fresh()->isManager());
    }
}
