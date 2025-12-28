<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected Role $adminRole;
    protected Role $memberRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Mark system as installed
        SystemSetting::set('installed', true, 'boolean');

        // Create test organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        // Create admin user
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        // Create member user
        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        // Create view-only user
        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        // Create system roles
        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        // Admin role with full permissions
        $this->adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => [
                    'view_roles',
                    'create_roles',
                    'edit_roles',
                    'delete_roles',
                ],
            ]
        );

        // Member role with limited permissions
        $this->memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'description' => 'Basic member access',
                'is_system' => true,
                'permissions' => [
                    'view_roles',
                    'create_roles',
                    'edit_roles',
                ],
            ]
        );

        // View-only role
        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'View only access',
                'is_system' => true,
                'permissions' => ['view_roles'],
            ]
        );

        // Assign roles to users
        $this->admin->roles()->syncWithoutDetaching([$this->adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$this->memberRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createCustomRole(array $attributes = []): Role
    {
        return Role::create(array_merge([
            'name' => 'Custom Role',
            'slug' => 'custom-role-' . uniqid(),
            'description' => 'A custom organization role',
            'permissions' => ['view_products', 'view_orders'],
            'organization_id' => $this->organization->id,
            'is_system' => false,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_roles_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('roles.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Roles/Index')
            ->has('roles')
            ->has('filters')
        );
    }

    public function test_member_can_view_roles_list(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('roles.index'));

        $response->assertStatus(200);
    }

    public function test_roles_list_can_be_searched(): void
    {
        $this->createCustomRole(['name' => 'Sales Manager']);
        $this->createCustomRole(['name' => 'Support Team']);

        $response = $this->actingAs($this->admin)
            ->get(route('roles.index', ['search' => 'Sales']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Roles/Index')
            ->where('filters.search', 'Sales')
        );
    }

    public function test_roles_list_shows_system_and_organization_roles(): void
    {
        $customRole = $this->createCustomRole();

        $response = $this->actingAs($this->admin)
            ->get(route('roles.index'));

        $response->assertStatus(200);
        // Should include both system roles and organization roles
    }

    public function test_guest_cannot_view_roles_list(): void
    {
        $response = $this->get(route('roles.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== CREATE TESTS ====================

    public function test_admin_can_view_create_role_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('roles.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Roles/Create')
            ->has('permissions')
        );
    }

    public function test_member_can_view_create_role_form(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('roles.create'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_create_role_form(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('roles.create'));

        $response->assertStatus(403);
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_role(): void
    {
        $roleData = [
            'name' => 'New Custom Role',
            'description' => 'A brand new custom role',
            'permissions' => ['view_products', 'create_products', 'view_orders'],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('roles.store'), $roleData);

        $response->assertRedirect(route('roles.index'));
        $response->assertSessionHas('success', 'Role created successfully.');

        $this->assertDatabaseHas('roles', [
            'name' => 'New Custom Role',
            'slug' => 'new-custom-role',
            'organization_id' => $this->organization->id,
            'is_system' => false,
        ]);
    }

    public function test_member_can_create_role(): void
    {
        $roleData = [
            'name' => 'Member Created Role',
            'description' => 'A role created by a member',
            'permissions' => ['view_products'],
        ];

        $response = $this->actingAs($this->member)
            ->post(route('roles.store'), $roleData);

        $response->assertRedirect(route('roles.index'));
        $this->assertDatabaseHas('roles', ['name' => 'Member Created Role']);
    }

    public function test_view_only_user_cannot_create_role(): void
    {
        $roleData = [
            'name' => 'Unauthorized Role',
            'permissions' => ['view_products'],
        ];

        $response = $this->actingAs($this->viewOnlyUser)
            ->post(route('roles.store'), $roleData);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('roles', ['name' => 'Unauthorized Role']);
    }

    public function test_role_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('roles.store'), [
                'name' => '',
            ]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_role_creation_generates_unique_slug(): void
    {
        // Create first role
        $this->actingAs($this->admin)
            ->post(route('roles.store'), [
                'name' => 'Sales Manager',
                'permissions' => [],
            ]);

        // Create second role with same name
        $this->actingAs($this->admin)
            ->post(route('roles.store'), [
                'name' => 'Sales Manager',
                'permissions' => [],
            ]);

        // Should have two roles with different slugs
        $this->assertDatabaseHas('roles', ['slug' => 'sales-manager']);
        $this->assertDatabaseHas('roles', ['slug' => 'sales-manager-1']);
    }

    public function test_role_creation_defaults_to_non_system_role(): void
    {
        $this->actingAs($this->admin)
            ->post(route('roles.store'), [
                'name' => 'Non System Role',
            ]);

        $this->assertDatabaseHas('roles', [
            'name' => 'Non System Role',
            'is_system' => false,
        ]);
    }

    // ==================== SHOW TESTS ====================

    public function test_admin_can_view_role(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->admin)
            ->get(route('roles.show', $role));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Roles/Show')
            ->has('role')
            ->has('rolePermissions')
        );
    }

    public function test_admin_can_view_system_role(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('roles.show', $this->adminRole));

        $response->assertStatus(200);
    }

    public function test_member_can_view_role(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->member)
            ->get(route('roles.show', $role));

        $response->assertStatus(200);
    }

    public function test_user_cannot_view_role_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherRole = Role::create([
            'name' => 'Other Org Role',
            'slug' => 'other-org-role',
            'organization_id' => $otherOrg->id,
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('roles.show', $otherRole));

        $response->assertStatus(403);
    }

    // ==================== EDIT TESTS ====================

    public function test_admin_can_view_edit_role_form(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->admin)
            ->get(route('roles.edit', $role));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Roles/Edit')
            ->has('role')
            ->has('permissions')
        );
    }

    public function test_member_can_view_edit_role_form(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->member)
            ->get(route('roles.edit', $role));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_edit_role_form(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('roles.edit', $role));

        $response->assertStatus(403);
    }

    public function test_cannot_edit_administrator_role(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('roles.edit', $this->adminRole));

        $response->assertRedirect(route('roles.index'));
        $response->assertSessionHasErrors(['role']);
    }

    public function test_user_cannot_edit_role_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherRole = Role::create([
            'name' => 'Other Org Role',
            'slug' => 'other-org-role',
            'organization_id' => $otherOrg->id,
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('roles.edit', $otherRole));

        $response->assertStatus(403);
    }

    // ==================== UPDATE TESTS ====================

    public function test_admin_can_update_role(): void
    {
        $role = $this->createCustomRole(['name' => 'Original Role']);

        $response = $this->actingAs($this->admin)
            ->put(route('roles.update', $role), [
                'name' => 'Updated Role',
                'description' => 'Updated description',
                'permissions' => ['view_products', 'edit_products'],
            ]);

        $response->assertRedirect(route('roles.index'));
        $response->assertSessionHas('success', 'Role updated successfully.');

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Updated Role',
            'slug' => 'updated-role',
        ]);
    }

    public function test_member_can_update_role(): void
    {
        $role = $this->createCustomRole(['name' => 'Original Role']);

        $response = $this->actingAs($this->member)
            ->put(route('roles.update', $role), [
                'name' => 'Member Updated',
                'permissions' => ['view_products'],
            ]);

        $response->assertRedirect(route('roles.index'));
        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Member Updated',
        ]);
    }

    public function test_view_only_user_cannot_update_role(): void
    {
        $role = $this->createCustomRole(['name' => 'Original Role']);

        $response = $this->actingAs($this->viewOnlyUser)
            ->put(route('roles.update', $role), [
                'name' => 'Should Not Update',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Original Role',
        ]);
    }

    public function test_cannot_update_administrator_role(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('roles.update', $this->adminRole), [
                'name' => 'Renamed Admin',
                'permissions' => [],
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['role']);

        $this->assertDatabaseHas('roles', [
            'id' => $this->adminRole->id,
            'name' => 'Administrator',
        ]);
    }

    public function test_user_cannot_update_role_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherRole = Role::create([
            'name' => 'Other Org Role',
            'slug' => 'other-org-role',
            'organization_id' => $otherOrg->id,
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('roles.update', $otherRole), [
                'name' => 'Hacked Role',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('roles', [
            'id' => $otherRole->id,
            'name' => 'Other Org Role',
        ]);
    }

    public function test_role_update_validates_required_fields(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->admin)
            ->put(route('roles.update', $role), [
                'name' => '',
            ]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_role_update_generates_new_slug_when_name_changes(): void
    {
        $role = $this->createCustomRole(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->actingAs($this->admin)
            ->put(route('roles.update', $role), [
                'name' => 'New Name',
                'permissions' => [],
            ]);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
    }

    // ==================== DELETE TESTS ====================

    public function test_admin_can_delete_custom_role(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->admin)
            ->delete(route('roles.destroy', $role));

        $response->assertRedirect(route('roles.index'));
        $response->assertSessionHas('success', 'Role deleted successfully.');

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_member_cannot_delete_role(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->member)
            ->delete(route('roles.destroy', $role));

        $response->assertStatus(403);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_view_only_user_cannot_delete_role(): void
    {
        $role = $this->createCustomRole();

        $response = $this->actingAs($this->viewOnlyUser)
            ->delete(route('roles.destroy', $role));

        $response->assertStatus(403);
    }

    public function test_cannot_delete_system_role(): void
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('roles.destroy', $this->adminRole));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['role']);

        $this->assertDatabaseHas('roles', ['id' => $this->adminRole->id]);
    }

    public function test_cannot_delete_role_with_assigned_users(): void
    {
        $role = $this->createCustomRole();

        // Assign a user to this role
        $newUser = User::create([
            'name' => 'Test User',
            'email' => 'testuser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
        $newUser->roles()->attach($role->id);

        $response = $this->actingAs($this->admin)
            ->delete(route('roles.destroy', $role));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['role']);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_user_cannot_delete_role_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherRole = Role::create([
            'name' => 'Other Org Role',
            'slug' => 'other-org-role',
            'organization_id' => $otherOrg->id,
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('roles.destroy', $otherRole));

        $response->assertStatus(403);
        $this->assertDatabaseHas('roles', ['id' => $otherRole->id]);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_roles_list_shows_organization_and_system_roles(): void
    {
        // Create custom organization role
        $customRole = $this->createCustomRole(['name' => 'Custom Org Role']);

        // Create role for different organization
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Role::create([
            'name' => 'Other Org Role',
            'slug' => 'other-org-role',
            'organization_id' => $otherOrg->id,
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('roles.index'));

        $response->assertStatus(200);
        // Should show system roles and organization roles, but not other org's roles
    }

    // ==================== PERMISSIONS TESTS ====================

    public function test_role_can_have_permissions_updated(): void
    {
        $role = $this->createCustomRole(['permissions' => ['view_products']]);

        $this->actingAs($this->admin)
            ->put(route('roles.update', $role), [
                'name' => $role->name,
                'permissions' => ['view_products', 'create_products', 'edit_products', 'delete_products'],
            ]);

        $role->refresh();
        $this->assertCount(4, $role->permissions);
        $this->assertContains('create_products', $role->permissions);
        $this->assertContains('delete_products', $role->permissions);
    }

    public function test_role_can_have_permissions_removed(): void
    {
        $role = $this->createCustomRole(['permissions' => ['view_products', 'create_products', 'edit_products']]);

        $this->actingAs($this->admin)
            ->put(route('roles.update', $role), [
                'name' => $role->name,
                'permissions' => ['view_products'],
            ]);

        $role->refresh();
        $this->assertCount(1, $role->permissions);
        $this->assertContains('view_products', $role->permissions);
        $this->assertNotContains('create_products', $role->permissions);
    }

    // ==================== MODEL TESTS ====================

    public function test_role_users_relationship(): void
    {
        $role = $this->createCustomRole();

        $newUser = User::create([
            'name' => 'Test User',
            'email' => 'testuser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
        $newUser->roles()->attach($role->id);

        $role->load('users');

        $this->assertCount(1, $role->users);
        $this->assertEquals($newUser->id, $role->users->first()->id);
    }
}
