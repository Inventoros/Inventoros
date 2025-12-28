<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected ProductCategory $category;
    protected ProductLocation $location;
    protected Product $product;

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

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
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
                    'view_stock_adjustments',
                    'manage_stock',
                    'view_products',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => ['view_stock_adjustments'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createStockAdjustment(array $attributes = []): StockAdjustment
    {
        return StockAdjustment::create(array_merge([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'user_id' => $this->admin->id,
            'type' => 'manual',
            'quantity_before' => 100,
            'quantity_change' => 10,
            'quantity_after' => 110,
            'reason' => 'Test adjustment',
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_stock_adjustments(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createStockAdjustment(['reason' => 'Adjustment 1']);
        $this->createStockAdjustment(['reason' => 'Adjustment 2']);

        $response = $this->getJson('/api/v1/stock-adjustments');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'product_id', 'type', 'quantity_change', 'reason'],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_adjustments_by_product(): void
    {
        Sanctum::actingAs($this->admin);

        $otherProduct = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'OTHER-001',
            'name' => 'Other Product',
            'price' => 50.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
        ]);

        $this->createStockAdjustment(['product_id' => $this->product->id]);
        $this->createStockAdjustment(['product_id' => $otherProduct->id]);

        $response = $this->getJson('/api/v1/stock-adjustments?product_id=' . $this->product->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_adjustments_by_type(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createStockAdjustment(['type' => 'manual']);
        $this->createStockAdjustment(['type' => 'damage']);

        $response = $this->getJson('/api/v1/stock-adjustments?type=manual');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_adjustments_are_paginated(): void
    {
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 20; $i++) {
            $this->createStockAdjustment();
        }

        $response = $this->getJson('/api/v1/stock-adjustments?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_unauthenticated_cannot_list_adjustments(): void
    {
        $response = $this->getJson('/api/v1/stock-adjustments');

        $response->assertStatus(401);
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_stock_adjustment(): void
    {
        Sanctum::actingAs($this->admin);

        $adjustmentData = [
            'product_id' => $this->product->id,
            'type' => 'manual',
            'quantity_change' => 25,
            'reason' => 'Inventory recount',
        ];

        $response = $this->postJson('/api/v1/stock-adjustments', $adjustmentData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Stock adjustment created successfully')
            ->assertJsonPath('data.quantity_change', 25);

        $this->product->refresh();
        $this->assertEquals(125, $this->product->stock);
    }

    public function test_can_create_negative_adjustment(): void
    {
        Sanctum::actingAs($this->admin);

        $initialStock = $this->product->stock;

        $adjustmentData = [
            'product_id' => $this->product->id,
            'type' => 'damage',
            'quantity_change' => -10,
            'reason' => 'Damaged items removed',
        ];

        $response = $this->postJson('/api/v1/stock-adjustments', $adjustmentData);

        $response->assertStatus(201);

        $this->product->refresh();
        $this->assertEquals($initialStock - 10, $this->product->stock);
    }

    public function test_create_adjustment_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/stock-adjustments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id', 'type', 'quantity_change']);
    }

    public function test_create_adjustment_validates_type(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id,
            'type' => 'invalid_type',
            'quantity_change' => 10,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_adjustment_records_user(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id,
            'type' => 'manual',
            'quantity_change' => 5,
            'reason' => 'Test',
        ]);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_adjustment_records_before_and_after_quantities(): void
    {
        Sanctum::actingAs($this->admin);

        $initialStock = $this->product->stock;

        $response = $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id,
            'type' => 'manual',
            'quantity_change' => 15,
            'reason' => 'Test',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quantity_before', $initialStock)
            ->assertJsonPath('data.quantity_after', $initialStock + 15);
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_stock_adjustment(): void
    {
        Sanctum::actingAs($this->admin);

        $adjustment = $this->createStockAdjustment(['reason' => 'View Test']);

        $response = $this->getJson("/api/v1/stock-adjustments/{$adjustment->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $adjustment->id)
            ->assertJsonPath('data.reason', 'View Test');
    }

    public function test_cannot_view_adjustment_from_different_organization(): void
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
            'price' => 50.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
        ]);

        $otherAdjustment = StockAdjustment::create([
            'organization_id' => $otherOrg->id,
            'product_id' => $otherProduct->id,
            'user_id' => $this->admin->id,
            'type' => 'manual',
            'quantity_before' => 50,
            'quantity_change' => 10,
            'quantity_after' => 60,
            'reason' => 'Other adjustment',
        ]);

        $response = $this->getJson("/api/v1/stock-adjustments/{$otherAdjustment->id}");

        $response->assertStatus(404);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_adjustments_list_only_shows_organization_adjustments(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createStockAdjustment(['reason' => 'Our Adjustment']);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherProduct = Product::create([
            'organization_id' => $otherOrg->id,
            'sku' => 'OTHER-001',
            'name' => 'Other Product',
            'price' => 50.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
        ]);

        StockAdjustment::create([
            'organization_id' => $otherOrg->id,
            'product_id' => $otherProduct->id,
            'user_id' => $this->admin->id,
            'type' => 'manual',
            'quantity_before' => 50,
            'quantity_change' => 10,
            'quantity_after' => 60,
            'reason' => 'Their Adjustment',
        ]);

        $response = $this->getJson('/api/v1/stock-adjustments');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Our Adjustment');
    }

    // ==================== ADJUSTMENT TYPE TESTS ====================

    public function test_can_create_recount_adjustment(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id,
            'type' => 'recount',
            'quantity_change' => -5,
            'reason' => 'Physical inventory count',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'recount');
    }

    public function test_can_create_damage_adjustment(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id,
            'type' => 'damage',
            'quantity_change' => -3,
            'reason' => 'Items damaged in transit',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'damage');
    }

    public function test_can_create_loss_adjustment(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id,
            'type' => 'loss',
            'quantity_change' => -2,
            'reason' => 'Items lost or stolen',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'loss');
    }

    public function test_can_create_return_adjustment(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id,
            'type' => 'return',
            'quantity_change' => 5,
            'reason' => 'Customer return',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'return');
    }
}
