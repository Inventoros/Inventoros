<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['manage_organization'],
            ]
        );

        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
    }

    public function test_admin_can_view_update_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.update.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_check_for_updates(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.update.check'));

        $response->assertStatus(200);
    }

    public function test_member_cannot_view_update_page(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('admin.update.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_update_page(): void
    {
        $response = $this->get(route('admin.update.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_with_manage_permission_cannot_check_for_updates(): void
    {
        // A non-admin (role=member → is_admin false) who nonetheless holds
        // manage_organization, so they pass the route middleware and reach the
        // controller guard. They must still be rejected by the is_admin check.
        $manager = User::create([
            'name' => 'Org Manager',
            'email' => 'manager@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
        $adminRole = Role::where('slug', 'system-administrator')->first();
        $manager->roles()->syncWithoutDetaching([$adminRole->id]);

        // sanity: they DO have the permission (so a 403 proves the guard, not the middleware)
        $this->assertTrue($manager->fresh()->hasPermission('manage_organization'));

        $response = $this->actingAs($manager)->get(route('admin.update.check'));

        $response->assertStatus(403);
    }

    public function test_restore_rejects_non_zip_backup_name(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.update.restore'), ['backup_file' => 'backup_x.php']);

        $response->assertStatus(422);
    }

    public function test_restore_rejects_backup_not_in_listing(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.update.restore'), ['backup_file' => 'backup_phantom.zip']);

        $response->assertStatus(404);
    }

    public function test_delete_backup_rejects_non_zip_backup_name(): void
    {
        $response = $this->actingAs($this->admin)
            ->deleteJson(route('admin.update.backup.delete'), ['backup_file' => 'evil.php']);

        $response->assertStatus(422);
    }
}
