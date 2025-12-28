<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Supplier;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewOnlyUser;
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

        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
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
                    'view_suppliers',
                    'manage_suppliers',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => ['view_suppliers'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createSupplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Supplier',
            'email' => 'supplier@test.com',
            'phone' => '123-456-7890',
            'is_active' => true,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_suppliers(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createSupplier(['name' => 'Supplier 1']);
        $this->createSupplier(['name' => 'Supplier 2']);

        $response = $this->getJson('/api/v1/suppliers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'email', 'phone', 'is_active'],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_can_search_suppliers(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createSupplier(['name' => 'Acme Corporation']);
        $this->createSupplier(['name' => 'Global Supplies']);

        $response = $this->getJson('/api/v1/suppliers?search=Acme');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Acme Corporation');
    }

    public function test_can_filter_active_suppliers(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createSupplier(['name' => 'Active Supplier', 'is_active' => true]);
        $this->createSupplier(['name' => 'Inactive Supplier', 'is_active' => false]);

        $response = $this->getJson('/api/v1/suppliers?is_active=true');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_suppliers_are_paginated(): void
    {
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 20; $i++) {
            $this->createSupplier(['name' => "Supplier $i"]);
        }

        $response = $this->getJson('/api/v1/suppliers?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_unauthenticated_cannot_list_suppliers(): void
    {
        $response = $this->getJson('/api/v1/suppliers');

        $response->assertStatus(401);
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_supplier(): void
    {
        Sanctum::actingAs($this->admin);

        $supplierData = [
            'name' => 'New Supplier',
            'email' => 'new@supplier.com',
            'phone' => '555-123-4567',
            'address' => '123 Supplier St',
            'city' => 'Supply City',
            'country' => 'USA',
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/suppliers', $supplierData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Supplier created successfully')
            ->assertJsonPath('data.name', 'New Supplier')
            ->assertJsonPath('data.email', 'new@supplier.com');

        $this->assertDatabaseHas('suppliers', [
            'name' => 'New Supplier',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_create_supplier_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/suppliers', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_supplier_validates_email_format(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/suppliers', [
            'name' => 'Test Supplier',
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_supplier(): void
    {
        Sanctum::actingAs($this->admin);

        $supplier = $this->createSupplier(['name' => 'View Test Supplier']);

        $response = $this->getJson("/api/v1/suppliers/{$supplier->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $supplier->id)
            ->assertJsonPath('data.name', 'View Test Supplier');
    }

    public function test_cannot_view_supplier_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherSupplier = Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Supplier',
        ]);

        $response = $this->getJson("/api/v1/suppliers/{$otherSupplier->id}");

        $response->assertStatus(404);
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_supplier(): void
    {
        Sanctum::actingAs($this->admin);

        $supplier = $this->createSupplier(['name' => 'Original Name']);

        $response = $this->putJson("/api/v1/suppliers/{$supplier->id}", [
            'name' => 'Updated Name',
            'phone' => '999-888-7777',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Supplier updated successfully')
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.phone', '999-888-7777');

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_cannot_update_supplier_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherSupplier = Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Supplier',
        ]);

        $response = $this->putJson("/api/v1/suppliers/{$otherSupplier->id}", [
            'name' => 'Hacked Name',
        ]);

        $response->assertStatus(404);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_supplier(): void
    {
        Sanctum::actingAs($this->admin);

        $supplier = $this->createSupplier();

        $response = $this->deleteJson("/api/v1/suppliers/{$supplier->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Supplier deleted successfully');

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    public function test_cannot_delete_supplier_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherSupplier = Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Supplier',
        ]);

        $response = $this->deleteJson("/api/v1/suppliers/{$otherSupplier->id}");

        $response->assertStatus(404);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_suppliers_list_only_shows_organization_suppliers(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createSupplier(['name' => 'Our Supplier']);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Their Supplier',
        ]);

        $response = $this->getJson('/api/v1/suppliers');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Our Supplier');
    }

    // ==================== SORTING TESTS ====================

    public function test_can_sort_suppliers(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createSupplier(['name' => 'Zebra Supplies']);
        $this->createSupplier(['name' => 'Alpha Supplies']);

        $response = $this->getJson('/api/v1/suppliers?sort_by=name&sort_dir=asc');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Alpha Supplies')
            ->assertJsonPath('data.1.name', 'Zebra Supplies');
    }
}
