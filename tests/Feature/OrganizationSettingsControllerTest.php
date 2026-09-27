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
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        // Create member user
        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->member->forceFill(['role' => 'member'])->save();

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

    public function test_guest_cannot_access_organization_settings(): void
    {
        $response = $this->get(route('settings.organization.index'));

        $response->assertRedirect(route('login'));
    }
}
