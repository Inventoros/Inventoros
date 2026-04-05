<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
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
                    'view_warehouses',
                    'manage_warehouses',
                    'view_products',
                    'manage_products',
                ],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    protected function createWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Warehouse',
            'code' => 'WH-' . uniqid(),
            'is_active' => true,
            'is_default' => false,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_warehouses(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createWarehouse(['name' => 'Warehouse A']);
        $this->createWarehouse(['name' => 'Warehouse B']);

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'code', 'is_active'],
                ],
            ])
            ->assertJsonCount(2, 'data');
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_warehouse(): void
    {
        Sanctum::actingAs($this->admin);

        $warehouseData = [
            'name' => 'New Warehouse',
            'code' => 'NW-001',
            'city' => 'Toronto',
            'province' => 'Ontario',
            'country' => 'Canada',
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/warehouses', $warehouseData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Warehouse created successfully')
            ->assertJsonPath('data.name', 'New Warehouse')
            ->assertJsonPath('data.code', 'NW-001');

        $this->assertDatabaseHas('warehouses', [
            'name' => 'New Warehouse',
            'organization_id' => $this->organization->id,
        ]);
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_warehouse(): void
    {
        Sanctum::actingAs($this->admin);

        $warehouse = $this->createWarehouse(['name' => 'View Test Warehouse']);

        $response = $this->getJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $warehouse->id)
            ->assertJsonPath('data.name', 'View Test Warehouse');
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_warehouse(): void
    {
        Sanctum::actingAs($this->admin);

        $warehouse = $this->createWarehouse(['name' => 'Original Name']);

        $response = $this->putJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Warehouse updated successfully')
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
            'name' => 'Updated Name',
        ]);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_warehouse(): void
    {
        Sanctum::actingAs($this->admin);

        $warehouse = $this->createWarehouse(['is_default' => false]);

        $response = $this->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Warehouse deleted successfully');

        $this->assertSoftDeleted('warehouses', ['id' => $warehouse->id]);
    }

    public function test_cannot_delete_default_warehouse(): void
    {
        Sanctum::actingAs($this->admin);

        $warehouse = $this->createWarehouse(['is_default' => true]);

        $response = $this->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertStatus(422)
            ->assertJsonPath('error', 'cannot_delete_default');

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
            'deleted_at' => null,
        ]);
    }

    // ==================== AUTH TESTS ====================

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->getJson('/api/v1/warehouses');

        $response->assertStatus(401);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_warehouses_scoped_to_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createWarehouse(['name' => 'Our Warehouse']);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Warehouse::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Their Warehouse',
            'code' => 'THEIR-001',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Our Warehouse');
    }
}
