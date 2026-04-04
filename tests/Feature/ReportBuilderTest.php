<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\SavedReport;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'CAD',
            'timezone' => 'America/Toronto',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->member->forceFill(['role' => 'member'])->save();

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
                    'view_reports', 'create_reports', 'edit_reports',
                    'delete_reports', 'view_products', 'view_orders',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createSavedReport(array $attributes = []): SavedReport
    {
        return SavedReport::create(array_merge([
            'organization_id' => $this->organization->id,
            'created_by' => $this->admin->id,
            'name' => 'Test Report',
            'description' => 'A test report',
            'data_source' => 'products',
            'columns' => ['name', 'sku', 'stock', 'price'],
            'filters' => null,
            'sort' => null,
            'chart_type' => null,
            'chart_field' => null,
            'is_shared' => false,
        ], $attributes));
    }

    protected function validReportData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My Custom Report',
            'description' => 'Report description',
            'data_source' => 'products',
            'columns' => ['name', 'sku', 'stock'],
            'filters' => null,
            'sort' => null,
            'chart_type' => null,
            'chart_field' => null,
            'is_shared' => false,
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // CRUD Operations
    // ---------------------------------------------------------------

    public function test_admin_can_view_saved_reports_list(): void
    {
        $this->createSavedReport();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.builder.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_create_report_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.builder.create'));

        $response->assertStatus(200);
    }

    public function test_admin_can_save_a_report_with_valid_config(): void
    {
        $data = $this->validReportData();

        $response = $this->actingAs($this->admin)
            ->post(route('reports.builder.store'), $data);

        $response->assertRedirect();

        $this->assertDatabaseHas('saved_reports', [
            'name' => 'My Custom Report',
            'data_source' => 'products',
            'organization_id' => $this->organization->id,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_admin_can_view_saved_report(): void
    {
        $report = $this->createSavedReport();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.builder.show', $report));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_edit_form_for_own_report(): void
    {
        $report = $this->createSavedReport();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.builder.edit', $report));

        $response->assertStatus(200);
    }

    public function test_admin_can_update_own_report(): void
    {
        $report = $this->createSavedReport();

        $response = $this->actingAs($this->admin)
            ->put(route('reports.builder.update', $report), $this->validReportData([
                'name' => 'Updated Report Name',
            ]));

        $response->assertRedirect(route('reports.builder.show', $report));

        $this->assertDatabaseHas('saved_reports', [
            'id' => $report->id,
            'name' => 'Updated Report Name',
        ]);
    }

    public function test_admin_can_delete_own_report(): void
    {
        $report = $this->createSavedReport();

        $response = $this->actingAs($this->admin)
            ->delete(route('reports.builder.destroy', $report));

        $response->assertRedirect(route('reports.builder.index'));

        $this->assertDatabaseMissing('saved_reports', [
            'id' => $report->id,
        ]);
    }

    public function test_cannot_edit_another_users_report(): void
    {
        // Give member view_reports permission so they can access the route group
        $memberRole = Role::firstOrCreate(
            ['slug' => 'report-viewer'],
            [
                'name' => 'Report Viewer',
                'is_system' => false,
                'permissions' => ['view_reports'],
            ]
        );
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);

        $report = $this->createSavedReport(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->member)
            ->get(route('reports.builder.edit', $report));

        $response->assertStatus(403);
    }

    public function test_cannot_delete_another_users_report(): void
    {
        $memberRole = Role::firstOrCreate(
            ['slug' => 'report-viewer'],
            [
                'name' => 'Report Viewer',
                'is_system' => false,
                'permissions' => ['view_reports'],
            ]
        );
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);

        $report = $this->createSavedReport(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->member)
            ->delete(route('reports.builder.destroy', $report));

        $response->assertStatus(403);

        $this->assertDatabaseHas('saved_reports', [
            'id' => $report->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Report Execution
    // ---------------------------------------------------------------

    public function test_can_preview_report_data(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Widget A',
            'sku' => 'WA-001',
            'price' => 25.00,
            'stock' => 100,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('reports.builder.preview'), [
                'data_source' => 'products',
                'columns' => ['name', 'sku', 'stock'],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'columnLabels']);
    }

    public function test_products_data_source_returns_correct_columns(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Product',
            'sku' => 'TP-001',
            'price' => 10.00,
            'stock' => 50,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('reports.builder.preview'), [
                'data_source' => 'products',
                'columns' => ['name', 'sku', 'price'],
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('name', (array) $data[0]);
        $this->assertArrayHasKey('sku', (array) $data[0]);
        $this->assertArrayHasKey('price', (array) $data[0]);
    }

    public function test_orders_data_source_returns_correct_columns(): void
    {
        Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-0001',
            'customer_name' => 'John Doe',
            'status' => 'pending',
            'total' => 100.00,
            'subtotal' => 90.00,
            'tax' => 10.00,
            'order_date' => now(),
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('reports.builder.preview'), [
                'data_source' => 'orders',
                'columns' => ['order_number', 'customer_name', 'status', 'total'],
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('order_number', (array) $data[0]);
        $this->assertArrayHasKey('customer_name', (array) $data[0]);
    }

    public function test_filters_are_applied_correctly(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Matching Product',
            'sku' => 'MP-001',
            'price' => 10.00,
            'stock' => 50,
        ]);

        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Product',
            'sku' => 'OP-001',
            'price' => 20.00,
            'stock' => 30,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('reports.builder.preview'), [
                'data_source' => 'products',
                'columns' => ['name', 'sku'],
                'filters' => [
                    ['field' => 'name', 'operator' => 'eq', 'value' => 'Matching Product'],
                ],
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Matching Product', $data[0]['name']);
    }

    public function test_sort_is_applied_correctly(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Beta Product',
            'sku' => 'BP-001',
            'price' => 30.00,
            'stock' => 10,
        ]);

        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Alpha Product',
            'sku' => 'AP-001',
            'price' => 20.00,
            'stock' => 20,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('reports.builder.preview'), [
                'data_source' => 'products',
                'columns' => ['name', 'sku'],
                'sort' => ['field' => 'name', 'direction' => 'asc'],
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals('Alpha Product', $data[0]['name']);
        $this->assertEquals('Beta Product', $data[1]['name']);
    }

    // ---------------------------------------------------------------
    // Export
    // ---------------------------------------------------------------

    public function test_can_export_report_as_csv(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Export Product',
            'sku' => 'EP-001',
            'price' => 15.00,
            'stock' => 25,
        ]);

        $report = $this->createSavedReport([
            'columns' => ['name', 'sku', 'price'],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.builder.export', $report));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_csv_contains_correct_headers(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'CSV Product',
            'sku' => 'CSV-001',
            'price' => 10.00,
            'stock' => 5,
        ]);

        $report = $this->createSavedReport([
            'columns' => ['name', 'sku', 'price'],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.builder.export', $report));

        $response->assertStatus(200);

        $content = $response->streamedContent();
        // Remove UTF-8 BOM
        $content = ltrim($content, "\xEF\xBB\xBF");
        $lines = explode("\n", trim($content));
        $headerLine = $lines[0];

        // The headers should be the labels from the data source config
        $this->assertStringContainsString('Name', $headerLine);
        $this->assertStringContainsString('SKU', $headerLine);
        $this->assertStringContainsString('Price', $headerLine);
    }

    // ---------------------------------------------------------------
    // Sharing
    // ---------------------------------------------------------------

    public function test_shared_reports_are_visible_to_other_org_users(): void
    {
        $memberRole = Role::firstOrCreate(
            ['slug' => 'report-viewer'],
            [
                'name' => 'Report Viewer',
                'is_system' => false,
                'permissions' => ['view_reports'],
            ]
        );
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);

        $report = $this->createSavedReport([
            'is_shared' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->member)
            ->get(route('reports.builder.show', $report));

        $response->assertStatus(200);
    }

    public function test_private_reports_are_only_visible_to_creator(): void
    {
        $memberRole = Role::firstOrCreate(
            ['slug' => 'report-viewer'],
            [
                'name' => 'Report Viewer',
                'is_system' => false,
                'permissions' => ['view_reports'],
            ]
        );
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);

        $report = $this->createSavedReport([
            'is_shared' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->member)
            ->get(route('reports.builder.show', $report));

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_report_requires_a_name(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('reports.builder.store'), $this->validReportData([
                'name' => '',
            ]));

        $response->assertSessionHasErrors(['name']);
    }

    public function test_report_requires_valid_data_source(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('reports.builder.store'), $this->validReportData([
                'data_source' => 'invalid_source',
            ]));

        $response->assertSessionHasErrors(['data_source']);
    }

    public function test_report_requires_at_least_one_column(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('reports.builder.store'), $this->validReportData([
                'columns' => [],
            ]));

        $response->assertSessionHasErrors(['columns']);
    }

    // ---------------------------------------------------------------
    // Permissions
    // ---------------------------------------------------------------

    public function test_unauthorized_user_cannot_access_report_builder(): void
    {
        // Member has no view_reports permission
        $response = $this->actingAs($this->member)
            ->get(route('reports.builder.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_access_report_builder(): void
    {
        $response = $this->get(route('reports.builder.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_cross_org_reports_not_visible(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
            'currency' => 'CAD',
            'timezone' => 'America/Toronto',
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
        ]);

        $report = $this->createSavedReport([
            'is_shared' => true,
        ]);

        // Give other user report permissions
        $otherUser->forceFill(['role' => 'admin'])->save();
        $adminRole = Role::where('slug', 'system-administrator')->first();
        $otherUser->roles()->syncWithoutDetaching([$adminRole->id]);

        $response = $this->actingAs($otherUser)
            ->get(route('reports.builder.show', $report));

        $response->assertStatus(403);
    }
}
