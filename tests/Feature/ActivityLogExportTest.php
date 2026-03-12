<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\ActivityLogExport;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ActivityLogExportTest extends TestCase
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
                'permissions' => ['view_activity_log', 'export_data'],
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

    public function test_admin_can_export_activity_log_as_csv(): void
    {
        Excel::fake();

        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'created',
            'description' => 'Created a product',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 1,
            'properties' => ['old' => [], 'new' => ['name' => 'Test']],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('activity-log.export', ['format' => 'csv']));

        $response->assertSuccessful();
    }

    public function test_admin_can_export_activity_log_as_xlsx(): void
    {
        Excel::fake();

        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'updated',
            'description' => 'Updated a product',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('activity-log.export', ['format' => 'xlsx']));

        $response->assertSuccessful();
    }

    public function test_export_respects_date_filters(): void
    {
        // Create logs at different dates
        $oldLog = ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'created',
            'description' => 'Old activity',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 1,
        ]);
        // Force update created_at to a specific date
        $oldLog->forceFill(['created_at' => '2026-01-01 10:00:00'])->save();

        $recentLog = ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'updated',
            'description' => 'Recent activity',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 2,
        ]);
        $recentLog->forceFill(['created_at' => '2026-03-10 10:00:00'])->save();

        $export = new ActivityLogExport($this->organization->id, [
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ]);

        $results = $export->query()->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Recent activity', $results->first()->description);
    }

    public function test_export_respects_user_filter(): void
    {
        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'created',
            'description' => 'Admin activity',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 1,
        ]);

        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'action' => 'created',
            'description' => 'Member activity',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 2,
        ]);

        $export = new ActivityLogExport($this->organization->id, [
            'user_id' => $this->member->id,
        ]);

        $results = $export->query()->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Member activity', $results->first()->description);
    }

    public function test_export_respects_action_filter(): void
    {
        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'created',
            'description' => 'Created something',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 1,
        ]);

        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'deleted',
            'description' => 'Deleted something',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 2,
        ]);

        $export = new ActivityLogExport($this->organization->id, [
            'action' => 'deleted',
        ]);

        $results = $export->query()->get();

        $this->assertCount(1, $results);
        $this->assertEquals('deleted', $results->first()->action);
    }

    public function test_export_has_correct_headings(): void
    {
        $export = new ActivityLogExport($this->organization->id);

        $headings = $export->headings();

        $this->assertEquals([
            'Date',
            'User',
            'Action',
            'Description',
            'Subject Type',
            'Subject ID',
            'Changes',
        ], $headings);
    }

    public function test_export_maps_data_correctly(): void
    {
        $log = ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'updated',
            'description' => 'Updated product name',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 42,
            'properties' => ['old' => ['name' => 'Old'], 'new' => ['name' => 'New']],
        ]);

        $log->load('user');

        $export = new ActivityLogExport($this->organization->id);
        $mapped = $export->map($log);

        $this->assertEquals('Admin User', $mapped[1]);
        $this->assertEquals('updated', $mapped[2]);
        $this->assertEquals('Updated product name', $mapped[3]);
        $this->assertEquals('Product', $mapped[4]);
        $this->assertEquals(42, $mapped[5]);
    }

    public function test_export_scoped_to_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'action' => 'created',
            'description' => 'Our activity',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 1,
        ]);

        ActivityLog::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $this->admin->id,
            'action' => 'created',
            'description' => 'Other org activity',
            'subject_type' => 'App\\Models\\Inventory\\Product',
            'subject_id' => 2,
        ]);

        $export = new ActivityLogExport($this->organization->id);
        $results = $export->query()->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Our activity', $results->first()->description);
    }

    public function test_member_cannot_export_activity_log(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('activity-log.export', ['format' => 'csv']));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_export_activity_log(): void
    {
        $response = $this->get(route('activity-log.export', ['format' => 'csv']));

        $response->assertRedirect(route('login'));
    }

    public function test_export_defaults_to_xlsx_format(): void
    {
        Excel::fake();

        $response = $this->actingAs($this->admin)
            ->get(route('activity-log.export'));

        $response->assertSuccessful();
    }
}
