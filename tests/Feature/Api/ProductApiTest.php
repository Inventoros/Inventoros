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

class ProductApiTest extends TestCase
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

        // Mark system as installed
        SystemSetting::set('installed', true, 'boolean');

        // Create test organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        // Create category and location
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

        // Create users
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

        // Create roles
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
                    'view_products',
                    'manage_products',
                    'view_orders',
                    'manage_orders',
                ],
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

    // ==================== INDEX TESTS ====================

    public function test_can_list_products(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct(['name' => 'Product 1']);
        $this->createProduct(['name' => 'Product 2']);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'sku', 'price', 'stock'],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_can_search_products(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct(['name' => 'Widget Alpha', 'sku' => 'WGT-001']);
        $this->createProduct(['name' => 'Gadget Beta', 'sku' => 'GDG-001']);

        $response = $this->getJson('/api/v1/products?search=Widget');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Widget Alpha');
    }

    public function test_can_filter_products_by_category(): void
    {
        Sanctum::actingAs($this->admin);

        $otherCategory = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Clothing',
            'slug' => 'clothing',
        ]);

        $this->createProduct(['name' => 'Electronics Item', 'category_id' => $this->category->id]);
        $this->createProduct(['name' => 'Clothing Item', 'category_id' => $otherCategory->id]);

        $response = $this->getJson('/api/v1/products?category_id=' . $this->category->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_low_stock_products(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct(['name' => 'Normal Stock', 'stock' => 100, 'min_stock' => 10]);
        $this->createProduct(['name' => 'Low Stock', 'stock' => 5, 'min_stock' => 10]);

        $response = $this->getJson('/api/v1/products?low_stock=true');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_products_are_paginated(): void
    {
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 20; $i++) {
            $this->createProduct(['name' => "Product $i"]);
        }

        $response = $this->getJson('/api/v1/products?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_unauthenticated_cannot_list_products(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(401);
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_product(): void
    {
        Sanctum::actingAs($this->admin);

        $productData = [
            'sku' => 'NEW-001',
            'name' => 'New Product',
            'description' => 'A new product description',
            'price' => 149.99,
            'purchase_price' => 75.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/products', $productData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Product created successfully')
            ->assertJsonPath('data.sku', 'NEW-001')
            ->assertJsonPath('data.name', 'New Product');

        $this->assertDatabaseHas('products', [
            'sku' => 'NEW-001',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_create_product_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/products', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sku', 'name']);
    }

    public function test_create_product_sets_default_values(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/products', [
            'sku' => 'DEFAULT-001',
            'name' => 'Default Product',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.stock', 0);
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_product(): void
    {
        Sanctum::actingAs($this->admin);

        $product = $this->createProduct(['name' => 'View Test Product']);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'View Test Product');
    }

    public function test_cannot_view_product_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-001',
            'name' => 'Other Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->getJson("/api/v1/products/{$otherProduct->id}");

        $response->assertStatus(404);
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_product(): void
    {
        Sanctum::actingAs($this->admin);

        $product = $this->createProduct(['name' => 'Original Name']);

        $response = $this->putJson("/api/v1/products/{$product->id}", [
            'name' => 'Updated Name',
            'price' => 199.99,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Product updated successfully')
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.price', 199.99);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_cannot_update_product_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-001',
            'name' => 'Other Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->putJson("/api/v1/products/{$otherProduct->id}", [
            'name' => 'Hacked Name',
        ]);

        $response->assertStatus(404);

        $this->assertDatabaseHas('products', [
            'id' => $otherProduct->id,
            'name' => 'Other Product',
        ]);
    }

    public function test_update_validates_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $product = $this->createProduct();

        $response = $this->putJson("/api/v1/products/{$product->id}", [
            'price' => -10, // Invalid
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_product(): void
    {
        Sanctum::actingAs($this->admin);

        $product = $this->createProduct();

        $response = $this->deleteJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Product deleted successfully');

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_cannot_delete_product_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-001',
            'name' => 'Other Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->deleteJson("/api/v1/products/{$otherProduct->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('products', [
            'id' => $otherProduct->id,
            'deleted_at' => null,
        ]);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_products_list_only_shows_organization_products(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct(['name' => 'Our Product']);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-001',
            'name' => 'Their Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Our Product');
    }

    // ==================== SORTING TESTS ====================

    public function test_can_sort_products(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createProduct(['name' => 'Zebra Product', 'price' => 50]);
        $this->createProduct(['name' => 'Apple Product', 'price' => 100]);

        $response = $this->getJson('/api/v1/products?sort_by=name&sort_dir=asc');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Apple Product')
            ->assertJsonPath('data.1.name', 'Zebra Product');
    }
}
