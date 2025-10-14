<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected Organization $organization;

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
            'city' => 'Test City',
            'state' => 'TS',
            'zip' => '12345',
            'country' => 'Test Country',
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

        // Create system roles if they don't exist
        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        // Create basic system roles
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => [
                    'view_settings',
                    'manage_organization',
                    'view_products',
                    'view_orders',
                    'view_users',
                    'create_users',
                    'edit_users',
                    'delete_users',
                ],
            ]
        );

        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'description' => 'Basic member access',
                'is_system' => true,
                'permissions' => ['view_products', 'view_orders'],
            ]
        );

        // Assign roles to users
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
    }

    public function test_admin_can_view_organization_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('settings.organization.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Organization/Index')
            ->has('organization')
            ->has('user')
        );
    }

    public function test_member_cannot_view_organization_settings_without_permission(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('settings.organization.index'));

        // Should be forbidden if the member doesn't have view_settings permission
        $response->assertStatus(403);
    }

    public function test_admin_can_update_general_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.general'), [
                'name' => 'Updated Organization',
                'email' => 'updated@organization.com',
                'phone' => '987-654-3210',
                'address' => '456 Updated Ave',
                'city' => 'Updated City',
                'state' => 'UP',
                'zip' => '54321',
                'country' => 'Updated Country',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Organization settings updated successfully.');

        $this->assertDatabaseHas('organizations', [
            'id' => $this->organization->id,
            'name' => 'Updated Organization',
            'email' => 'updated@organization.com',
            'phone' => '987-654-3210',
        ]);
    }

    public function test_member_cannot_update_general_settings(): void
    {
        $response = $this->actingAs($this->member)
            ->patch(route('settings.organization.update.general'), [
                'name' => 'Should Not Update',
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('organizations', [
            'id' => $this->organization->id,
            'name' => 'Test Organization', // Should remain unchanged
        ]);
    }

    public function test_admin_can_update_regional_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.regional'), [
                'currency' => 'EUR',
                'timezone' => 'Europe/Paris',
                'date_format' => 'd/m/Y',
                'time_format' => 'H:i:s',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Regional settings updated successfully.');

        // Refresh the organization and check it was updated
        $this->organization->refresh();
        $this->assertEquals('EUR', $this->organization->currency);
        $this->assertEquals('Europe/Paris', $this->organization->timezone);
    }

    public function test_validation_fails_for_invalid_general_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.general'), [
                'name' => '', // Required field
                'email' => 'invalid-email', // Invalid email
            ]);

        $response->assertSessionHasErrors(['name', 'email']);
    }

    public function test_validation_fails_for_invalid_regional_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.regional'), [
                'currency' => 'INVALID', // Must be 3 characters max
                'timezone' => '', // Required field
            ]);

        $response->assertSessionHasErrors(['currency', 'timezone']);
    }

    public function test_admin_can_create_user(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('settings.organization.users.store'), [
                'name' => 'New User',
                'email' => 'newuser@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_admin' => false,
            ]);

        $response->assertRedirect();
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
        $response = $this->actingAs($this->admin)
            ->post(route('settings.organization.users.store'), [
                'name' => 'New Admin',
                'email' => 'newadmin@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_admin' => true,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('users', [
            'name' => 'New Admin',
            'email' => 'newadmin@test.com',
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
    }

    public function test_member_cannot_create_user(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('settings.organization.users.store'), [
                'name' => 'Unauthorized User',
                'email' => 'unauthorized@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_admin' => false,
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('users', [
            'email' => 'unauthorized@test.com',
        ]);
    }

    public function test_admin_can_update_user(): void
    {
        $response = $this->actingAs($this->admin)
            ->patch(route('settings.organization.users.update', $this->member->id), [
                'name' => 'Updated Member',
                'email' => 'updatedmember@test.com',
                'is_admin' => false,
            ]);

        $response->assertRedirect();
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
            ->patch(route('settings.organization.users.update', $this->member->id), [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'is_admin' => true,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $this->member->id,
            'role' => 'admin',
        ]);
    }

    public function test_cannot_remove_admin_role_from_last_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->patch(route('settings.organization.users.update', $this->admin->id), [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'is_admin' => false,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['is_admin']);

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'role' => 'admin', // Should remain admin
        ]);
    }

    public function test_admin_cannot_update_user_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('settings.organization.users.update', $otherUser->id), [
                'name' => 'Hacked',
                'email' => 'hacked@test.com',
                'is_admin' => false,
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
            'name' => 'Other User', // Should remain unchanged
        ]);
    }

    public function test_admin_can_delete_user(): void
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('settings.organization.users.destroy', $this->member->id));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'User deleted successfully.');

        $this->assertDatabaseMissing('users', [
            'id' => $this->member->id,
        ]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('settings.organization.users.destroy', $this->admin->id));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['user']);

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
        ]);
    }

    public function test_cannot_delete_last_admin(): void
    {
        // Try to delete the only admin
        $response = $this->actingAs($this->admin)
            ->delete(route('settings.organization.users.destroy', $this->admin->id));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['user']);

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
        ]);
    }

    public function test_member_cannot_delete_user(): void
    {
        $response = $this->actingAs($this->member)
            ->delete(route('settings.organization.users.destroy', $this->admin->id));

        $response->assertStatus(403);

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
        ]);
    }

    public function test_guest_cannot_access_organization_settings(): void
    {
        $response = $this->get(route('settings.organization.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_creation_validates_unique_email(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('settings.organization.users.store'), [
                'name' => 'Duplicate User',
                'email' => $this->member->email, // Already exists
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_admin' => false,
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_user_creation_validates_password_confirmation(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('settings.organization.users.store'), [
                'name' => 'New User',
                'email' => 'newuser@test.com',
                'password' => 'password123',
                'password_confirmation' => 'differentpassword',
                'is_admin' => false,
            ]);

        $response->assertSessionHasErrors(['password']);
    }
}
