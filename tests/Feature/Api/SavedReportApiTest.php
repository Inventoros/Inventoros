<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\SavedReport;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavedReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $otherUser;
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

        $this->otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@test.com',
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
                'permissions' => [
                    'view_reports',
                    'manage_reports',
                    'view_products',
                ],
            ]
        );

        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'is_system' => true,
                'permissions' => [
                    'view_reports',
                    'view_products',
                ],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->otherUser->roles()->syncWithoutDetaching([$memberRole->id]);
    }

    protected function createSavedReport(array $attributes = []): SavedReport
    {
        return SavedReport::create(array_merge([
            'organization_id' => $this->organization->id,
            'created_by' => $this->admin->id,
            'name' => 'Test Report',
            'data_source' => 'products',
            'columns' => ['name', 'sku', 'stock'],
            'is_shared' => false,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_saved_reports(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createSavedReport(['name' => 'Report 1']);
        $this->createSavedReport(['name' => 'Report 2']);

        $response = $this->getJson('/api/v1/reports');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'data_source', 'is_shared', 'is_owner'],
                ],
            ])
            ->assertJsonCount(2, 'data');
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_saved_report(): void
    {
        Sanctum::actingAs($this->admin);

        $reportData = [
            'name' => 'Inventory Summary',
            'description' => 'A summary of all inventory',
            'data_source' => 'products',
            'columns' => ['name', 'sku', 'stock'],
            'is_shared' => true,
        ];

        $response = $this->postJson('/api/v1/reports', $reportData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Report created successfully')
            ->assertJsonPath('data.name', 'Inventory Summary');

        $this->assertDatabaseHas('saved_reports', [
            'name' => 'Inventory Summary',
            'organization_id' => $this->organization->id,
            'created_by' => $this->admin->id,
        ]);
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_saved_report(): void
    {
        Sanctum::actingAs($this->admin);

        $report = $this->createSavedReport(['name' => 'View Test Report']);

        $response = $this->getJson("/api/v1/reports/{$report->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'report' => ['id', 'name', 'data_source', 'columns'],
                    'rows',
                    'total',
                ],
            ]);
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_own_report(): void
    {
        Sanctum::actingAs($this->admin);

        $report = $this->createSavedReport(['name' => 'Original Name']);

        $response = $this->putJson("/api/v1/reports/{$report->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Report updated successfully');

        $this->assertDatabaseHas('saved_reports', [
            'id' => $report->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_cannot_update_another_users_report(): void
    {
        Sanctum::actingAs($this->otherUser);

        $report = $this->createSavedReport([
            'name' => 'Admin Report',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->putJson("/api/v1/reports/{$report->id}", [
            'name' => 'Hijacked Name',
        ]);

        $response->assertStatus(404);

        $this->assertDatabaseHas('saved_reports', [
            'id' => $report->id,
            'name' => 'Admin Report',
        ]);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_own_report(): void
    {
        Sanctum::actingAs($this->admin);

        $report = $this->createSavedReport();

        $response = $this->deleteJson("/api/v1/reports/{$report->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Report deleted successfully');

        $this->assertDatabaseMissing('saved_reports', [
            'id' => $report->id,
        ]);
    }

    // ==================== EXPORT TESTS ====================

    public function test_can_export_report_as_csv(): void
    {
        Sanctum::actingAs($this->admin);

        $report = $this->createSavedReport(['name' => 'Export Test']);

        $response = $this->get("/api/v1/reports/{$report->id}/export");

        $response->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // ==================== AUTH TESTS ====================

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->getJson('/api/v1/reports');

        $response->assertStatus(401);
    }
}
