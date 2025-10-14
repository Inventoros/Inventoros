<?php

namespace Tests\Unit;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $admin;
    protected User $manager;
    protected User $member;
    protected Role $adminRole;
    protected Role $managerRole;
    protected Role $memberRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Mark system as installed
        SystemSetting::set('installed', true, 'boolean');

        // Create organization
        $this->organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'test@org.com',
        ]);

        // Create roles
        $this->adminRole = Role::create([
            'name' => 'Administrator',
            'slug' => 'system-administrator',
            'organization_id' => $this->organization->id,
            'is_system' => true,
            'permissions' => [
                'view_settings',
                'manage_organization',
                'view_users',
                'create_users',
                'edit_users',
                'delete_users',
            ],
        ]);

        $this->managerRole = Role::create([
            'name' => 'Manager',
            'slug' => 'system-manager',
            'organization_id' => $this->organization->id,
            'is_system' => true,
            'permissions' => [
                'view_settings',
                'view_products',
                'view_orders',
            ],
        ]);

        $this->memberRole = Role::create([
            'name' => 'Member',
            'slug' => 'system-member',
            'organization_id' => $this->organization->id,
            'is_system' => true,
            'permissions' => [
                'view_products',
            ],
        ]);

        // Create users
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
        $this->admin->roles()->attach($this->adminRole);

        $this->manager = User::create([
            'name' => 'Manager User',
            'email' => 'manager@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'manager',
        ]);
        $this->manager->roles()->attach($this->managerRole);

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
        $this->member->roles()->attach($this->memberRole);
    }

    public function test_admin_has_view_settings_permission(): void
    {
        $this->assertTrue($this->admin->hasPermission('view_settings'));
    }

    public function test_admin_has_manage_organization_permission(): void
    {
        $this->assertTrue($this->admin->hasPermission('manage_organization'));
    }

    public function test_manager_has_view_settings_permission(): void
    {
        $this->assertTrue($this->manager->hasPermission('view_settings'));
    }

    public function test_manager_does_not_have_manage_organization_permission(): void
    {
        $this->assertFalse($this->manager->hasPermission('manage_organization'));
    }

    public function test_member_does_not_have_view_settings_permission(): void
    {
        $this->assertFalse($this->member->hasPermission('view_settings'));
    }

    public function test_member_does_not_have_manage_organization_permission(): void
    {
        $this->assertFalse($this->member->hasPermission('manage_organization'));
    }

    public function test_admin_can_access_organization_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('settings.organization.index'));

        $response->assertStatus(200);
    }

    public function test_manager_can_access_organization_settings_with_view_permission(): void
    {
        $response = $this->actingAs($this->manager)
            ->get(route('settings.organization.index'));

        $response->assertStatus(200);
    }

    public function test_member_cannot_access_organization_settings(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('settings.organization.index'));

        $response->assertStatus(403);
    }

    public function test_admin_can_update_organization_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.general'), [
                'name' => 'Updated Name',
                'email' => 'updated@test.com',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_manager_cannot_update_organization_settings(): void
    {
        $response = $this->actingAs($this->manager)
            ->patch(route('settings.organization.update.general'), [
                'name' => 'Updated Name',
                'email' => 'updated@test.com',
            ]);

        $response->assertStatus(403);
    }

    public function test_all_users_can_access_account_settings(): void
    {
        $adminResponse = $this->actingAs($this->admin)
            ->get(route('settings.account.index'));
        $adminResponse->assertStatus(200);

        $managerResponse = $this->actingAs($this->manager)
            ->get(route('settings.account.index'));
        $managerResponse->assertStatus(200);

        $memberResponse = $this->actingAs($this->member)
            ->get(route('settings.account.index'));
        $memberResponse->assertStatus(200);
    }

    public function test_all_users_can_update_their_profile(): void
    {
        $adminResponse = $this->actingAs($this->admin)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Updated Admin',
                'email' => 'admin.updated@test.com',
            ]);
        $adminResponse->assertRedirect();

        $managerResponse = $this->actingAs($this->manager)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Updated Manager',
                'email' => 'manager.updated@test.com',
            ]);
        $managerResponse->assertRedirect();

        $memberResponse = $this->actingAs($this->member)
            ->patch(route('settings.account.update.profile'), [
                'name' => 'Updated Member',
                'email' => 'member.updated@test.com',
            ]);
        $memberResponse->assertRedirect();
    }

    public function test_all_users_can_update_their_password(): void
    {
        $response = $this->actingAs($this->member)
            ->patch(route('settings.account.update.password'), [
                'current_password' => 'password',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_admin_can_create_users(): void
    {
        $this->assertTrue($this->admin->hasPermission('create_users'));
        $this->assertTrue($this->admin->hasPermission('edit_users'));
        $this->assertTrue($this->admin->hasPermission('delete_users'));
    }

    public function test_manager_cannot_create_users(): void
    {
        $this->assertFalse($this->manager->hasPermission('create_users'));
        $this->assertFalse($this->manager->hasPermission('edit_users'));
        $this->assertFalse($this->manager->hasPermission('delete_users'));
    }

    public function test_legacy_settings_route_redirects_to_organization_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('settings.index'));

        $response->assertRedirect(route('settings.organization.index'));
    }

    public function test_guest_cannot_access_organization_settings(): void
    {
        $response = $this->get(route('settings.organization.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_account_settings(): void
    {
        $response = $this->get(route('settings.account.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_is_admin_check_works_correctly(): void
    {
        $this->assertTrue($this->admin->isAdmin());
        $this->assertFalse($this->manager->isAdmin());
        $this->assertFalse($this->member->isAdmin());
    }

    public function test_user_is_manager_check_works_correctly(): void
    {
        $this->assertTrue($this->admin->isManager()); // Admins are also managers
        $this->assertTrue($this->manager->isManager());
        $this->assertFalse($this->member->isManager());
    }

    public function test_admin_has_all_permissions_via_is_admin_check(): void
    {
        // Admins should have access to everything
        $this->assertTrue($this->admin->hasPermission('any_random_permission'));
        $this->assertTrue($this->admin->hasPermission('another_permission'));
    }

    public function test_users_can_have_multiple_roles(): void
    {
        $customRole = Role::create([
            'name' => 'Custom Role',
            'slug' => 'custom-role',
            'organization_id' => $this->organization->id,
            'permissions' => ['custom_permission'],
        ]);

        $this->member->roles()->attach($customRole);

        $this->assertTrue($this->member->hasPermission('custom_permission'));
        $this->assertTrue($this->member->hasRole('custom-role'));
        $this->assertTrue($this->member->hasRole('system-member'));
    }

    public function test_user_can_check_for_any_of_multiple_permissions(): void
    {
        $this->assertTrue($this->admin->hasAnyPermission(['view_settings', 'nonexistent']));
        $this->assertFalse($this->member->hasAnyPermission(['view_settings', 'manage_organization']));
    }

    public function test_user_can_check_for_all_of_multiple_permissions(): void
    {
        $this->assertTrue($this->admin->hasAllPermissions(['view_settings', 'manage_organization']));
        $this->assertFalse($this->manager->hasAllPermissions(['view_settings', 'manage_organization']));
    }

    public function test_user_get_all_permissions_returns_correct_permissions(): void
    {
        $adminPermissions = $this->admin->getAllPermissions();
        $this->assertContains('view_settings', $adminPermissions);
        $this->assertContains('manage_organization', $adminPermissions);

        $managerPermissions = $this->manager->getAllPermissions();
        $this->assertContains('view_settings', $managerPermissions);
        $this->assertNotContains('manage_organization', $managerPermissions);

        $memberPermissions = $this->member->getAllPermissions();
        $this->assertNotContains('view_settings', $memberPermissions);
        $this->assertContains('view_products', $memberPermissions);
    }
}
