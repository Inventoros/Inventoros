<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\WorkOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Organization $organization;
    protected Product $assemblyProduct;
    protected Product $componentProduct1;
    protected Product $componentProduct2;
    protected Product $standardProduct;

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

        $this->assemblyProduct = Product::create([
            'organization_id' => $this->organization->id,
            'type' => 'assembly',
            'sku' => 'ASM-001',
            'name' => 'Assembled Widget',
            'price' => 199.99,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 5,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $this->componentProduct1 = Product::create([
            'organization_id' => $this->organization->id,
            'type' => 'standard',
            'sku' => 'COMP-001',
            'name' => 'Component A',
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $this->componentProduct2 = Product::create([
            'organization_id' => $this->organization->id,
            'type' => 'standard',
            'sku' => 'COMP-002',
            'name' => 'Component B',
            'price' => 20.00,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        $this->standardProduct = Product::create([
            'organization_id' => $this->organization->id,
            'type' => 'standard',
            'sku' => 'STD-001',
            'name' => 'Standard Product',
            'price' => 49.99,
            'currency' => 'USD',
            'stock' => 50,
            'min_stock' => 5,
            'category_id' => $category->id,
            'location_id' => $location->id,
        ]);

        // Create BOM for assembly product
        ProductComponent::create([
            'parent_product_id' => $this->assemblyProduct->id,
            'component_product_id' => $this->componentProduct1->id,
            'quantity' => 2,
            'sort_order' => 0,
        ]);

        ProductComponent::create([
            'parent_product_id' => $this->assemblyProduct->id,
            'component_product_id' => $this->componentProduct2->id,
            'quantity' => 1,
            'sort_order' => 1,
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
                    'view_work_orders',
                    'manage_work_orders',
                    'manage_stock',
                    'view_products',
                    'manage_products',
                ],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    // ==================== INDEX TESTS ====================

    public function test_can_list_work_orders(): void
    {
        Sanctum::actingAs($this->admin);

        WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->assemblyProduct->id,
            'created_by' => $this->admin->id,
            'work_order_number' => 'WO-TEST-0001',
            'quantity' => 5,
            'status' => 'draft',
        ]);

        WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->assemblyProduct->id,
            'created_by' => $this->admin->id,
            'work_order_number' => 'WO-TEST-0002',
            'quantity' => 10,
            'status' => 'draft',
        ]);

        $response = $this->getJson('/api/v1/work-orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'work_order_number', 'status', 'quantity'],
                ],
            ])
            ->assertJsonCount(2, 'data');
    }

    // ==================== STORE TESTS ====================

    public function test_can_create_work_order_for_assembly_product(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/work-orders', [
            'product_id' => $this->assemblyProduct->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Work order created successfully')
            ->assertJsonPath('data.product_id', $this->assemblyProduct->id)
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('work_orders', [
            'organization_id' => $this->organization->id,
            'product_id' => $this->assemblyProduct->id,
            'quantity' => 5,
        ]);

        // Check that work order items were created from BOM
        $workOrder = WorkOrder::where('product_id', $this->assemblyProduct->id)->first();
        $this->assertCount(2, $workOrder->items);
    }

    public function test_cannot_create_work_order_for_standard_product(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/work-orders', [
            'product_id' => $this->standardProduct->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'invalid_product_type');
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_work_order(): void
    {
        Sanctum::actingAs($this->admin);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->assemblyProduct->id,
            'created_by' => $this->admin->id,
            'work_order_number' => 'WO-VIEW-0001',
            'quantity' => 5,
            'status' => 'draft',
        ]);

        $response = $this->getJson("/api/v1/work-orders/{$workOrder->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $workOrder->id)
            ->assertJsonPath('data.work_order_number', 'WO-VIEW-0001');
    }

    // ==================== START TESTS ====================

    public function test_can_start_work_order(): void
    {
        Sanctum::actingAs($this->admin);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->assemblyProduct->id,
            'created_by' => $this->admin->id,
            'work_order_number' => 'WO-START-0001',
            'quantity' => 5,
            'status' => 'draft',
        ]);

        // Create work order items with quantities that are satisfiable
        $workOrder->items()->create([
            'product_id' => $this->componentProduct1->id,
            'quantity_required' => 10, // 5 units * 2 each
            'quantity_consumed' => 0,
        ]);

        $workOrder->items()->create([
            'product_id' => $this->componentProduct2->id,
            'quantity_required' => 5, // 5 units * 1 each
            'quantity_consumed' => 0,
        ]);

        $response = $this->postJson("/api/v1/work-orders/{$workOrder->id}/start");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Work order started successfully');

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'status' => 'in_progress',
        ]);
    }

    // ==================== COMPLETE TESTS ====================

    public function test_can_complete_work_order(): void
    {
        Sanctum::actingAs($this->admin);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->assemblyProduct->id,
            'created_by' => $this->admin->id,
            'work_order_number' => 'WO-COMP-0001',
            'quantity' => 5,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $workOrder->items()->create([
            'product_id' => $this->componentProduct1->id,
            'quantity_required' => 10,
            'quantity_consumed' => 0,
        ]);

        $workOrder->items()->create([
            'product_id' => $this->componentProduct2->id,
            'quantity_required' => 5,
            'quantity_consumed' => 0,
        ]);

        $initialAssemblyStock = $this->assemblyProduct->stock;
        $initialComp1Stock = $this->componentProduct1->stock;
        $initialComp2Stock = $this->componentProduct2->stock;

        $response = $this->postJson("/api/v1/work-orders/{$workOrder->id}/complete");

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'completed']);

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'status' => 'completed',
        ]);

        // Check stock adjustments: assembly increased, components decreased
        $this->assemblyProduct->refresh();
        $this->componentProduct1->refresh();
        $this->componentProduct2->refresh();

        $this->assertEquals($initialAssemblyStock + 5, $this->assemblyProduct->stock);
        $this->assertEquals($initialComp1Stock - 10, $this->componentProduct1->stock);
        $this->assertEquals($initialComp2Stock - 5, $this->componentProduct2->stock);
    }

    // ==================== CANCEL TESTS ====================

    public function test_can_cancel_work_order(): void
    {
        Sanctum::actingAs($this->admin);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->assemblyProduct->id,
            'created_by' => $this->admin->id,
            'work_order_number' => 'WO-CANCEL-0001',
            'quantity' => 5,
            'status' => 'draft',
        ]);

        $response = $this->postJson("/api/v1/work-orders/{$workOrder->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Work order has been cancelled');

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'status' => 'cancelled',
        ]);
    }

    // ==================== AUTH TESTS ====================

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->getJson('/api/v1/work-orders');

        $response->assertStatus(401);
    }
}
