<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewOnlyUser;
    protected Organization $organization;
    protected Supplier $supplier;
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

        $this->supplier = Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Supplier',
            'email' => 'supplier@test.com',
            'is_active' => true,
        ]);

        $category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'purchase_price' => 50.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'category_id' => $category->id,
            'location_id' => $location->id,
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
                    'view_purchase_orders',
                    'manage_purchase_orders',
                    'receive_purchase_orders',
                    'edit_purchase_orders',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => ['view_purchase_orders'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createPurchaseOrder(array $attributes = []): PurchaseOrder
    {
        return PurchaseOrder::create(array_merge([
            'organization_id' => $this->organization->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-' . uniqid(),
            'status' => 'draft',
            'subtotal' => 500.00,
            'tax' => 50.00,
            'total' => 550.00,
            'currency' => 'USD',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_purchase_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createPurchaseOrder(['po_number' => 'PO-001']);
        $this->createPurchaseOrder(['po_number' => 'PO-002']);

        $response = $this->getJson('/api/v1/purchase-orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'po_number', 'supplier_id', 'status', 'total'],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_purchase_orders_by_status(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createPurchaseOrder(['status' => 'draft']);
        $this->createPurchaseOrder(['status' => 'sent']);

        $response = $this->getJson('/api/v1/purchase-orders?status=draft');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_purchase_orders_by_supplier(): void
    {
        Sanctum::actingAs($this->admin);

        $otherSupplier = Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Supplier',
        ]);

        $this->createPurchaseOrder(['supplier_id' => $this->supplier->id]);
        $this->createPurchaseOrder(['supplier_id' => $otherSupplier->id]);

        $response = $this->getJson('/api/v1/purchase-orders?supplier_id=' . $this->supplier->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_purchase_orders_are_paginated(): void
    {
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 20; $i++) {
            $this->createPurchaseOrder();
        }

        $response = $this->getJson('/api/v1/purchase-orders?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_unauthenticated_cannot_list_purchase_orders(): void
    {
        $response = $this->getJson('/api/v1/purchase-orders');

        $response->assertStatus(401);
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_purchase_order(): void
    {
        Sanctum::actingAs($this->admin);

        $poData = [
            'po_number' => 'PO-NEW-001',
            'supplier_id' => $this->supplier->id,
            'expected_date' => now()->addDays(7)->format('Y-m-d'),
            'notes' => 'Test purchase order',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_price' => 50.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/purchase-orders', $poData);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Purchase order created successfully')
            ->assertJsonPath('data.po_number', 'PO-NEW-001');

        $this->assertDatabaseHas('purchase_orders', [
            'po_number' => 'PO-NEW-001',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_create_purchase_order_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/purchase-orders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_purchase_order(): void
    {
        Sanctum::actingAs($this->admin);

        $po = $this->createPurchaseOrder(['po_number' => 'PO-VIEW-001']);

        $response = $this->getJson("/api/v1/purchase-orders/{$po->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $po->id)
            ->assertJsonPath('data.po_number', 'PO-VIEW-001');
    }

    public function test_cannot_view_purchase_order_from_different_organization(): void
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

        $otherPo = PurchaseOrder::create([
            'organization_id' => $otherOrg->id,
            'supplier_id' => $otherSupplier->id,
            'po_number' => 'OTHER-001',
            'status' => 'draft',
            'total' => 100.00,
        ]);

        $response = $this->getJson("/api/v1/purchase-orders/{$otherPo->id}");

        $response->assertStatus(404);
    }

    // ==================== UPDATE TESTS ====================

    public function test_can_update_purchase_order(): void
    {
        Sanctum::actingAs($this->admin);

        $po = $this->createPurchaseOrder(['status' => 'draft', 'notes' => 'Original notes']);

        $response = $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'notes' => 'Updated notes',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Purchase order updated successfully')
            ->assertJsonPath('data.notes', 'Updated notes');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'notes' => 'Updated notes',
        ]);
    }

    public function test_cannot_update_purchase_order_from_different_organization(): void
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

        $otherPo = PurchaseOrder::create([
            'organization_id' => $otherOrg->id,
            'supplier_id' => $otherSupplier->id,
            'po_number' => 'OTHER-001',
            'status' => 'draft',
            'total' => 100.00,
        ]);

        $response = $this->putJson("/api/v1/purchase-orders/{$otherPo->id}", [
            'notes' => 'Hacked',
        ]);

        $response->assertStatus(404);
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_purchase_order(): void
    {
        Sanctum::actingAs($this->admin);

        $po = $this->createPurchaseOrder();

        $response = $this->deleteJson("/api/v1/purchase-orders/{$po->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Purchase order deleted successfully');

        $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
    }

    public function test_cannot_delete_purchase_order_from_different_organization(): void
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

        $otherPo = PurchaseOrder::create([
            'organization_id' => $otherOrg->id,
            'supplier_id' => $otherSupplier->id,
            'po_number' => 'OTHER-001',
            'status' => 'draft',
            'total' => 100.00,
        ]);

        $response = $this->deleteJson("/api/v1/purchase-orders/{$otherPo->id}");

        $response->assertStatus(404);
    }

    // ==================== STATUS WORKFLOW TESTS ====================

    public function test_can_send_purchase_order(): void
    {
        Sanctum::actingAs($this->admin);

        $po = $this->createPurchaseOrder(['status' => 'draft']);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/send");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Purchase order sent successfully');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'sent',
        ]);
    }

    public function test_can_cancel_purchase_order(): void
    {
        Sanctum::actingAs($this->admin);

        $po = $this->createPurchaseOrder(['status' => 'draft']);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Purchase order cancelled successfully');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_can_receive_purchase_order(): void
    {
        Sanctum::actingAs($this->admin);

        $po = $this->createPurchaseOrder(['status' => 'sent']);

        // Attach items to the PO
        $po->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 50.00,
            'total' => 500.00,
        ]);

        $initialStock = $this->product->stock;

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity_received' => 10,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Purchase order received successfully');

        $this->product->refresh();
        $this->assertEquals($initialStock + 10, $this->product->stock);
    }

    public function test_can_partially_receive_purchase_order(): void
    {
        Sanctum::actingAs($this->admin);

        $po = $this->createPurchaseOrder(['status' => 'sent']);

        $po->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 50.00,
            'total' => 500.00,
        ]);

        $initialStock = $this->product->stock;

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity_received' => 5,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->product->refresh();
        $this->assertEquals($initialStock + 5, $this->product->stock);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'partial',
        ]);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_purchase_orders_list_only_shows_organization_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createPurchaseOrder(['po_number' => 'OUR-001']);

        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherSupplier = Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Supplier',
        ]);

        PurchaseOrder::create([
            'organization_id' => $otherOrg->id,
            'supplier_id' => $otherSupplier->id,
            'po_number' => 'THEIR-001',
            'status' => 'draft',
            'total' => 100.00,
        ]);

        $response = $this->getJson('/api/v1/purchase-orders');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.po_number', 'OUR-001');
    }
}
