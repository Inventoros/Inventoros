<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Order;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
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
                    'view_orders',
                    'manage_orders',
                    'view_products',
                    'manage_products',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => ['view_orders'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-' . uniqid(),
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'status' => 'pending',
            'subtotal' => 99.99,
            'tax' => 10.00,
            'total' => 109.99,
            'currency' => 'USD',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createOrder(['customer_name' => 'Customer 1']);
        $this->createOrder(['customer_name' => 'Customer 2']);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'order_number', 'customer_name', 'status', 'total'],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_can_search_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createOrder(['customer_name' => 'John Smith', 'order_number' => 'ORD-001']);
        $this->createOrder(['customer_name' => 'Jane Doe', 'order_number' => 'ORD-002']);

        $response = $this->getJson('/api/v1/orders?search=John');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'John Smith');
    }

    public function test_can_filter_orders_by_status(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createOrder(['status' => 'pending']);
        $this->createOrder(['status' => 'completed']);

        $response = $this->getJson('/api/v1/orders?status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_orders_are_paginated(): void
    {
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 20; $i++) {
            $this->createOrder();
        }

        $response = $this->getJson('/api/v1/orders?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_unauthenticated_cannot_list_orders(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(401);
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_order(): void
    {
        Sanctum::actingAs($this->admin);

        $orderData = [
            'order_number' => 'ORD-NEW-001',
            'customer_name' => 'New Customer',
            'customer_email' => 'new@customer.com',
            'customer_phone' => '123-456-7890',
            'status' => 'pending',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 99.99,
                ],
            ],
            'notes' => 'Test order',
        ];

        $response = $this->postJson('/api/v1/orders', $orderData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Order created successfully')
            ->assertJsonPath('data.order_number', 'ORD-NEW-001')
            ->assertJsonPath('data.customer_name', 'New Customer');

        $this->assertDatabaseHas('orders', [
            'order_number' => 'ORD-NEW-001',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_create_order_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/orders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name']);
    }

    public function test_create_order_adjusts_stock(): void
    {
        Sanctum::actingAs($this->admin);

        $initialStock = $this->product->stock;

        $orderData = [
            'order_number' => 'ORD-STOCK-001',
            'customer_name' => 'Stock Test Customer',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'unit_price' => 99.99,
                ],
            ],
        ];

        $this->postJson('/api/v1/orders', $orderData);

        $this->product->refresh();
        $this->assertEquals($initialStock - 5, $this->product->stock);
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_order(): void
    {
        Sanctum::actingAs($this->admin);

        $order = $this->createOrder(['customer_name' => 'View Test Customer']);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.customer_name', 'View Test Customer');
    }

    public function test_cannot_view_order_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherOrder = Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'OTHER-001',
            'customer_name' => 'Other Customer',
            'status' => 'pending',
            'subtotal' => 50.00,
            'total' => 50.00,
        ]);

        $response = $this->getJson("/api/v1/orders/{$otherOrder->id}");

        $response->assertStatus(404);
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_order(): void
    {
        Sanctum::actingAs($this->admin);

        $order = $this->createOrder(['status' => 'pending']);

        $response = $this->putJson("/api/v1/orders/{$order->id}", [
            'status' => 'processing',
            'customer_name' => 'Updated Customer',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Order updated successfully')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.customer_name', 'Updated Customer');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'processing',
        ]);
    }

    public function test_cannot_update_order_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherOrder = Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'OTHER-001',
            'customer_name' => 'Other Customer',
            'status' => 'pending',
            'subtotal' => 50.00,
            'total' => 50.00,
        ]);

        $response = $this->putJson("/api/v1/orders/{$otherOrder->id}", [
            'status' => 'completed',
        ]);

        $response->assertStatus(404);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_order(): void
    {
        Sanctum::actingAs($this->admin);

        $order = $this->createOrder();

        $response = $this->deleteJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Order deleted successfully');

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_cannot_delete_order_from_different_organization(): void
    {
        Sanctum::actingAs($this->admin);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherOrder = Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'OTHER-001',
            'customer_name' => 'Other Customer',
            'status' => 'pending',
            'subtotal' => 50.00,
            'total' => 50.00,
        ]);

        $response = $this->deleteJson("/api/v1/orders/{$otherOrder->id}");

        $response->assertStatus(404);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_orders_list_only_shows_organization_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createOrder(['customer_name' => 'Our Customer']);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Order::create([
            'organization_id' => $otherOrg->id,
            'order_number' => 'OTHER-001',
            'customer_name' => 'Their Customer',
            'status' => 'pending',
            'subtotal' => 50.00,
            'total' => 50.00,
        ]);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Our Customer');
    }

    // ==================== SORTING TESTS ====================

    public function test_can_sort_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createOrder(['customer_name' => 'Zebra Customer', 'total' => 50]);
        $this->createOrder(['customer_name' => 'Apple Customer', 'total' => 100]);

        $response = $this->getJson('/api/v1/orders?sort_by=customer_name&sort_dir=asc');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.customer_name', 'Apple Customer')
            ->assertJsonPath('data.1.customer_name', 'Zebra Customer');
    }

    // ==================== STATUS WORKFLOW TESTS ====================

    public function test_can_mark_order_as_shipped(): void
    {
        Sanctum::actingAs($this->admin);

        $order = $this->createOrder(['status' => 'processing']);

        $response = $this->putJson("/api/v1/orders/{$order->id}", [
            'status' => 'shipped',
            'tracking_number' => 'TRACK123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'shipped');
    }

    public function test_can_mark_order_as_delivered(): void
    {
        Sanctum::actingAs($this->admin);

        $order = $this->createOrder(['status' => 'shipped']);

        $response = $this->putJson("/api/v1/orders/{$order->id}", [
            'status' => 'delivered',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');
    }
}
