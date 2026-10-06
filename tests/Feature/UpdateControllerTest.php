<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Update\DatabaseBackupFailedException;
use App\Services\UpdateService;
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

    public function test_backup_response_reports_the_database_backup_method(): void
    {
        $this->mock(UpdateService::class, function ($mock) {
            $mock->shouldReceive('createBackup')->once()->andReturn('/backups/backup_x.zip');
            $mock->shouldReceive('lastDatabaseBackupMethod')->andReturn('pg_dump');
        });

        $response = $this->actingAs($this->admin)->postJson(route('admin.update.backup'));

        $response->assertOk()
            ->assertJson(['success' => true, 'databaseMethod' => 'pg_dump']);
        $this->assertStringContainsString('pg_dump', $response->json('message'));
    }

    public function test_backup_response_surfaces_a_database_backup_failure(): void
    {
        $this->mock(UpdateService::class, function ($mock) {
            $mock->shouldReceive('createBackup')->once()->andThrow(
                new DatabaseBackupFailedException('Could not back up the database with any available method')
            );
        });

        $response = $this->actingAs($this->admin)->postJson(route('admin.update.backup'));

        $response->assertStatus(500)->assertJson(['success' => false]);
        $this->assertStringContainsString('Could not back up the database', $response->json('message'));
    }

    public function test_another_tenants_admin_cannot_access_any_installation_update_or_backup_route(): void
    {
        $tenant = Organization::create(['name' => 'Other tenant', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create([
            'name' => 'Tenant admin', 'email' => 'tenant-admin@test.com', 'password' => bcrypt('password'),
            'organization_id' => $tenant->id, 'role' => 'admin',
        ]);
        $this->mock(UpdateService::class, function ($mock) {
            foreach (['getCurrentVersion', 'getLatestRelease', 'isUpdateAvailable', 'listBackups', 'update', 'createBackup', 'restoreFromBackup'] as $method) {
                $mock->shouldNotReceive($method);
            }
        });
        $this->actingAs($admin);

        foreach (['index', 'check', 'backups.list'] as $route) {
            $this->get(route('admin.update.'.$route))->assertForbidden();
        }
        foreach (['perform', 'backup', 'restore'] as $route) {
            $this->postJson(route('admin.update.'.$route), ['backup_file' => 'backup_known.zip'])->assertForbidden();
        }
        $this->deleteJson(route('admin.update.backup.delete'), ['backup_file' => 'backup_known.zip'])->assertForbidden();

        $this->get(route('settings.index'))->assertInertia(fn ($page) => $page
            ->where('auth.canManageUpdates', false));
    }

    public function test_the_configured_installation_administrator_can_manage_updates(): void
    {
        $tenant = Organization::create(['name' => 'Configured owner', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create([
            'name' => 'Owner admin', 'email' => 'owner-admin@test.com', 'password' => bcrypt('password'),
            'organization_id' => $tenant->id, 'role' => 'admin',
        ]);
        config(['plugins.admin_organization_id' => $tenant->id]);
        $this->mock(UpdateService::class, function ($mock) {
            $mock->shouldReceive('listBackups')->once()->andReturn([]);
            $mock->shouldReceive('update')->once()->andReturn(['success' => true]);
        });

        $this->actingAs($this->admin)->get(route('admin.update.backups.list'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.update.backups.list'))->assertOk();
        $this->postJson(route('admin.update.perform'))->assertOk()->assertJson(['success' => true]);
        $this->get(route('settings.index'))->assertInertia(fn ($page) => $page
            ->where('auth.canManageUpdates', true));
    }

    public function test_a_non_admin_with_manage_organization_cannot_list_installation_backups(): void
    {
        $this->member->roles()->attach(Role::where('slug', 'system-administrator')->firstOrFail());
        $this->mock(UpdateService::class, fn ($mock) => $mock->shouldNotReceive('listBackups'));

        $this->actingAs($this->member)->get(route('admin.update.backups.list'))->assertForbidden();
    }
}
