<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductLocationApiTest extends TestCase
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
                    'view_locations',
                    'manage_locations',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => ['view_locations'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createLocation(array $attributes = []): ProductLocation
    {
        return ProductLocation::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Location',
            'code' => 'LOC-' . uniqid(),
            'is_active' => true,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_locations(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createLocation(['name' => 'Location 1', 'code' => 'LOC-1']);
        $this->createLocation(['name' => 'Location 2', 'code' => 'LOC-2']);

        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'code', 'is_active'],
                ],
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_can_search_locations(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createLocation(['name' => 'Warehouse A', 'code' => 'WH-A']);
        $this->createLocation(['name' => 'Store Front', 'code' => 'SF-1']);

        $response = $this->getJson('/api/v1/locations?search=Warehouse');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Warehouse A');
    }

    public function test_can_filter_active_locations(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createLocation(['name' => 'Active', 'code' => 'ACT', 'is_active' => true]);
        $this->createLocation(['name' => 'Inactive', 'code' => 'INA', 'is_active' => false]);

        $response = $this->getJson('/api/v1/locations?is_active=true');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_cannot_list_locations(): void
    {
        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(401);
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_location(): void
    {
        Sanctum::actingAs($this->admin);

        $locationData = [
            'name' => 'New Location',
            'code' => 'NEW-LOC',
            'description' => 'A new location',
            'address' => '123 Warehouse St',
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/locations', $locationData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Location created successfully')
            ->assertJsonPath('data.name', 'New Location')
            ->assertJsonPath('data.code', 'NEW-LOC');

        $this->assertDatabaseHas('product_locations', [
            'name' => 'New Location',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_create_location_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/locations', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code']);
    }

    public function test_create_location_validates_unique_code(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createLocation(['code' => 'EXISTING']);

        $response = $this->postJson('/api/v1/locations', [
            'name' => 'New Location',
            'code' => 'EXISTING',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_location(): void
    {
        Sanctum::actingAs($this->admin);

        $location = $this->createLocation(['name' => 'View Test']);

        $response = $this->getJson("/api/v1/locations/{$location->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $location->id)
            ->assertJsonPath('data.name', 'View Test');
    }

    public function test_cannot_view_location_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherLocation = ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Location',
            'code' => 'OTHER',
        ]);

        $response = $this->getJson("/api/v1/locations/{$otherLocation->id}");

        $response->assertStatus(404);
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_location(): void
    {
        Sanctum::actingAs($this->admin);

        $location = $this->createLocation(['name' => 'Original']);

        $response = $this->putJson("/api/v1/locations/{$location->id}", [
            'name' => 'Updated',
            'description' => 'Updated description',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Location updated successfully')
            ->assertJsonPath('data.name', 'Updated');

        $this->assertDatabaseHas('product_locations', [
            'id' => $location->id,
            'name' => 'Updated',
        ]);
    }

    public function test_cannot_update_location_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherLocation = ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Location',
            'code' => 'OTHER',
        ]);

        $response = $this->putJson("/api/v1/locations/{$otherLocation->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(404);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_location(): void
    {
        Sanctum::actingAs($this->admin);

        $location = $this->createLocation();

        $response = $this->deleteJson("/api/v1/locations/{$location->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Location deleted successfully');

        $this->assertDatabaseMissing('product_locations', ['id' => $location->id]);
    }

    public function test_cannot_delete_location_with_products(): void
    {
        Sanctum::actingAs($this->admin);

        $location = $this->createLocation();

        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Category',
            'slug' => 'category',
        ]);

        Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $response = $this->deleteJson("/api/v1/locations/{$location->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete location with associated products');
    }

    public function test_cannot_delete_location_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherLocation = ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Location',
            'code' => 'OTHER',
        ]);

        $response = $this->deleteJson("/api/v1/locations/{$otherLocation->id}");

        $response->assertStatus(404);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_locations_list_only_shows_organization_locations(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createLocation(['name' => 'Our Location', 'code' => 'OUR']);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        ProductLocation::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Their Location',
            'code' => 'THEIR',
        ]);

        $response = $this->getJson('/api/v1/locations');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Our Location');
    }

    // ==================== SORTING TESTS ====================

    public function test_can_sort_locations(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createLocation(['name' => 'Zebra Warehouse', 'code' => 'ZW']);
        $this->createLocation(['name' => 'Alpha Warehouse', 'code' => 'AW']);

        $response = $this->getJson('/api/v1/locations?sort_by=name&sort_dir=asc');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Alpha Warehouse')
            ->assertJsonPath('data.1.name', 'Zebra Warehouse');
    }
}
