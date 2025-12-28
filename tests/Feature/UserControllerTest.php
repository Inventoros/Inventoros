<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected Role $adminRole;

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
                    'view_users',
                    'create_users',
                    'edit_users',
                    'delete_users',
                ],
            ]
        );

        // Member role with limited permissions
        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'description' => 'Basic member access',
                'is_system' => true,
                'permissions' => [
                    'view_users',
                    'create_users',
                    'edit_users',
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
                'permissions' => ['view_users'],
            ]
        );

        // Assign roles to users
        $this->admin->roles()->syncWithoutDetaching([$this->adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_users_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('users.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users')
            ->has('roles')
            ->has('filters')
        );
    }

    public function test_member_can_view_users_list(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('users.index'));

        $response->assertStatus(200);
    }

    public function test_users_list_can_be_searched(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('users.index', ['search' => 'Admin']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->where('filters.search', 'Admin')
        );
    }

    public function test_users_list_can_be_filtered_by_role(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('users.index', ['role' => 'admin']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('filters.role', 'admin')
        );
    }

    public function test_guest_cannot_view_users_list(): void
    {
        $response = $this->get(route('users.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== CREATE TESTS ====================

    public function test_admin_can_view_create_user_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('users.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Create')
            ->has('roles')
        );
    }

    public function test_member_can_view_create_user_form(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('users.create'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_create_user_form(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('users.create'));

        $response->assertStatus(403);
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_user(): void
    {
        $userData = [
            'name' => 'New User',
            'email' => 'newuser@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'member',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('users.store'), $userData);

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('success', 'User created successfully.');

        $this->assertDatabaseHas('users', [
            'name' => 'New User',
            'email' => 'newuser@test.com',
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
    }

    public function test_admin_can_create_admin_user(): void
    {
        $userData = [
            'name' => 'New Admin',
            'email' => 'newadmin@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'admin',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('users.store'), $userData);

        $response->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'newadmin@test.com',
            'role' => 'admin',
        ]);
    }

    public function test_member_can_create_user(): void
    {
        $userData = [
            'name' => 'Member Created User',
            'email' => 'membercreated@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'member',
        ];

        $response = $this->actingAs($this->member)
            ->post(route('users.store'), $userData);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'membercreated@test.com']);
    }

    public function test_view_only_user_cannot_create_user(): void
    {
        $userData = [
            'name' => 'Unauthorized User',
            'email' => 'unauthorized@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'member',
        ];

        $response = $this->actingAs($this->viewOnlyUser)
            ->post(route('users.store'), $userData);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@test.com']);
    }

    public function test_user_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => '',
                'email' => '',
                'password' => '',
                'role' => '',
            ]);

        $response->assertSessionHasErrors(['name', 'email', 'password', 'role']);
    }

    public function test_user_creation_validates_unique_email(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Duplicate Email User',
                'email' => $this->member->email,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'role' => 'member',
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_user_creation_validates_password_confirmation(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Mismatched Password User',
                'email' => 'mismatch@test.com',
                'password' => 'Password123!',
                'password_confirmation' => 'DifferentPassword!',
                'role' => 'member',
            ]);

        $response->assertSessionHasErrors(['password']);
    }

    public function test_user_creation_validates_valid_role(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('users.store'), [
                'name' => 'Invalid Role User',
                'email' => 'invalidrole@test.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'role' => 'superadmin', // Invalid role
            ]);

        $response->assertSessionHasErrors(['role']);
    }

    public function test_user_can_be_created_with_custom_roles(): void
    {
        $customRole = Role::create([
            'name' => 'Custom Role',
            'slug' => 'custom-role',
            'organization_id' => $this->organization->id,
            'is_system' => false,
            'permissions' => ['view_products'],
        ]);

        $userData = [
            'name' => 'User With Custom Role',
            'email' => 'customrole@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'member',
            'role_ids' => [$customRole->id],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('users.store'), $userData);

        $response->assertRedirect(route('users.index'));

        $newUser = User::where('email', 'customrole@test.com')->first();
        $this->assertTrue($newUser->roles->contains($customRole->id));
    }

    // ==================== SHOW TESTS ====================

    public function test_admin_can_view_user(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('users.show', $this->member));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Show')
            ->has('user')
        );
    }

    public function test_member_can_view_user(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('users.show', $this->admin));

        $response->assertStatus(200);
    }

    public function test_user_cannot_view_user_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherUser = User::create([
            'name' => 'Other Org User',
            'email' => 'otherorguser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('users.show', $otherUser));

        $response->assertStatus(403);
    }

    // ==================== EDIT TESTS ====================

    public function test_admin_can_view_edit_user_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('users.edit', $this->member));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Edit')
            ->has('user')
            ->has('roles')
        );
    }

    public function test_member_can_view_edit_user_form(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('users.edit', $this->admin));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_edit_user_form(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('users.edit', $this->member));

        $response->assertStatus(403);
    }

    public function test_user_cannot_edit_user_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherUser = User::create([
            'name' => 'Other Org User',
            'email' => 'otherorguser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('users.edit', $otherUser));

        $response->assertStatus(403);
    }

    // ==================== UPDATE TESTS ====================

    public function test_admin_can_update_user(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $this->member), [
                'name' => 'Updated Member',
                'email' => 'updatedmember@test.com',
                'role' => 'member',
            ]);

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('success', 'User updated successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $this->member->id,
            'name' => 'Updated Member',
            'email' => 'updatedmember@test.com',
        ]);
    }

    public function test_admin_can_promote_user_to_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $this->member), [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'role' => 'admin',
            ]);

        $response->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $this->member->id,
            'role' => 'admin',
        ]);
    }

    public function test_member_can_update_user(): void
    {
        $response = $this->actingAs($this->member)
            ->put(route('users.update', $this->viewOnlyUser), [
                'name' => 'Member Updated User',
                'email' => $this->viewOnlyUser->email,
                'role' => 'member',
            ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'id' => $this->viewOnlyUser->id,
            'name' => 'Member Updated User',
        ]);
    }

    public function test_view_only_user_cannot_update_user(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->put(route('users.update', $this->member), [
                'name' => 'Should Not Update',
                'email' => $this->member->email,
                'role' => 'member',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $this->member->id,
            'name' => 'Member User',
        ]);
    }

    public function test_cannot_remove_admin_from_last_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $this->admin), [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => 'member',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['role']);

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'role' => 'admin',
        ]);
    }

    public function test_user_cannot_update_user_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherUser = User::create([
            'name' => 'Other Org User',
            'email' => 'otherorguser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $otherUser), [
                'name' => 'Hacked User',
                'email' => $otherUser->email,
                'role' => 'admin',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
            'name' => 'Other Org User',
        ]);
    }

    public function test_user_password_can_be_updated(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $this->member), [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
                'role' => 'member',
            ]);

        $response->assertRedirect(route('users.index'));

        $this->member->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $this->member->password));
    }

    public function test_user_update_validates_unique_email(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $this->member), [
                'name' => $this->member->name,
                'email' => $this->admin->email, // Duplicate email
                'role' => 'member',
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_user_can_update_with_same_email(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('users.update', $this->member), [
                'name' => 'Updated Name',
                'email' => $this->member->email, // Same email
                'role' => 'member',
            ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'id' => $this->member->id,
            'name' => 'Updated Name',
        ]);
    }

    // ==================== DELETE TESTS ====================

    public function test_admin_can_delete_user(): void
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('users.destroy', $this->member));

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('success', 'User deleted successfully.');

        $this->assertDatabaseMissing('users', ['id' => $this->member->id]);
    }

    public function test_member_cannot_delete_user(): void
    {
        $response = $this->actingAs($this->member)
            ->delete(route('users.destroy', $this->viewOnlyUser));

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $this->viewOnlyUser->id]);
    }

    public function test_view_only_user_cannot_delete_user(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->delete(route('users.destroy', $this->member));

        $response->assertStatus(403);
    }

    public function test_cannot_delete_yourself(): void
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('users.destroy', $this->admin));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['user']);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_cannot_delete_last_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('users.destroy', $this->admin));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['user']);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_can_delete_admin_if_not_last_admin(): void
    {
        // Create another admin
        $secondAdmin = User::create([
            'name' => 'Second Admin',
            'email' => 'secondadmin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('users.destroy', $secondAdmin));

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseMissing('users', ['id' => $secondAdmin->id]);
    }

    public function test_user_cannot_delete_user_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherUser = User::create([
            'name' => 'Other Org User',
            'email' => 'otherorguser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('users.destroy', $otherUser));

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_users_list_only_shows_organization_users(): void
    {
        // Create user for different organization
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        User::create([
            'name' => 'Other Org User',
            'email' => 'otherorguser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('users.index'));

        $response->assertStatus(200);
        // Should only show 3 users (admin, member, viewOnly) not the other org user
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users.data', 3)
        );
    }

    // ==================== MODEL TESTS ====================

    public function test_user_roles_relationship(): void
    {
        $this->admin->load('roles');

        $this->assertNotEmpty($this->admin->roles);
        $this->assertTrue($this->admin->roles->contains($this->adminRole->id));
    }

    public function test_user_organization_relationship(): void
    {
        $this->admin->load('organization');

        $this->assertNotNull($this->admin->organization);
        $this->assertEquals($this->organization->id, $this->admin->organization->id);
    }
}
