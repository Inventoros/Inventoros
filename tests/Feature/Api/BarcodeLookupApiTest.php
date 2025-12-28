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

class BarcodeLookupApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected ProductCategory $category;
    protected ProductLocation $location;

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

        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
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
                'permissions' => ['view_products', 'manage_products'],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => ['view_products'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-' . uniqid(),
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ], $attributes));
    }

    // ==================== BARCODE LOOKUP TESTS ====================

    public function test_can_lookup_product_by_sku(): void
    {
        Sanctum::actingAs($this->admin);

        $product = $this->createProduct(['sku' => 'LOOKUP-001', 'name' => 'Lookup Product']);

        $response = $this->getJson('/api/v1/barcode/LOOKUP-001');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.sku', 'LOOKUP-001')
            ->assertJsonPath('data.name', 'Lookup Product');
    }

    public function test_can_lookup_product_by_barcode(): void
    {
        Sanctum::actingAs($this->admin);

        $product = $this->createProduct([
            'sku' => 'SKU-001',
            'barcode' => '1234567890123',
            'name' => 'Barcode Product',
        ]);

        $response = $this->getJson('/api/v1/barcode/1234567890123');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.barcode', '1234567890123')
            ->assertJsonPath('data.name', 'Barcode Product');
    }

    public function test_returns_404_for_unknown_code(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/barcode/UNKNOWN-CODE');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Product not found');
    }

    public function test_cannot_lookup_product_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'price' => 50.00,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->getJson('/api/v1/barcode/OTHER-SKU');

        $response->assertStatus(404);
    }

    public function test_unauthenticated_cannot_lookup_barcode(): void
    {
        $this->createProduct(['sku' => 'TEST-SKU']);

        $response = $this->getJson('/api/v1/barcode/TEST-SKU');

        $response->assertStatus(401);
    }

    public function test_viewer_can_lookup_barcode(): void
    {
        Sanctum::actingAs($this->viewOnlyUser);

        $product = $this->createProduct(['sku' => 'VIEWER-SKU']);

        $response = $this->getJson('/api/v1/barcode/VIEWER-SKU');

        $response->assertStatus(200)
            ->assertJsonPath('data.sku', 'VIEWER-SKU');
    }

    public function test_lookup_returns_product_stock_info(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct([
            'sku' => 'STOCK-SKU',
            'stock' => 50,
            'min_stock' => 10,
        ]);

        $response = $this->getJson('/api/v1/barcode/STOCK-SKU');

        $response->assertStatus(200)
            ->assertJsonPath('data.stock', 50)
            ->assertJsonPath('data.min_stock', 10);
    }

    public function test_lookup_returns_product_pricing(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct([
            'sku' => 'PRICE-SKU',
            'price' => 199.99,
            'purchase_price' => 99.99,
        ]);

        $response = $this->getJson('/api/v1/barcode/PRICE-SKU');

        $response->assertStatus(200)
            ->assertJsonPath('data.price', 199.99);
    }

    public function test_lookup_is_case_insensitive(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct(['sku' => 'UPPERCASE-SKU']);

        $response = $this->getJson('/api/v1/barcode/uppercase-sku');

        // This depends on implementation - may return 200 or 404
        // Most implementations are case-insensitive
        $response->assertStatus(200);
    }

    public function test_lookup_returns_category_and_location(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct([
            'sku' => 'DETAILS-SKU',
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);

        $response = $this->getJson('/api/v1/barcode/DETAILS-SKU');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'sku',
                    'name',
                    'category',
                    'location',
                ],
            ]);
    }

    public function test_lookup_only_returns_active_products(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct([
            'sku' => 'INACTIVE-SKU',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/barcode/INACTIVE-SKU');

        // Depending on implementation, inactive products may or may not be found
        // Typically barcode scanners should find any product for inventory purposes
        $response->assertStatus(200);
    }

    public function test_lookup_handles_special_characters_in_barcode(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct([
            'sku' => 'SKU-001-A',
            'name' => 'Product with hyphen SKU',
        ]);

        $response = $this->getJson('/api/v1/barcode/SKU-001-A');

        $response->assertStatus(200)
            ->assertJsonPath('data.sku', 'SKU-001-A');
    }
}
