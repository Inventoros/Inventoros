<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewer;
    protected Organization $organization;
    protected ProductCategory $category;
    protected ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Category',
            'slug' => 'test-category',
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

        $this->viewer = User::create([
            'name' => 'Viewer User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'viewer',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => [
                    'view_products',
                    'create_products',
                    'edit_products',
                    'delete_products',
                    'manage_stock',
                    'view_orders',
                    'create_orders',
                    'edit_orders',
                    'delete_orders',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'View only access',
                'is_system' => true,
                'permissions' => ['view_products'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewer->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-' . uniqid(),
            'name' => 'Test Product',
            'price' => 99.99,
            'purchase_price' => 50.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ], $attributes));
    }

    /**
     * Create an assembly product with components already attached.
     */
    protected function createAssemblyWithComponents(int $assemblyStock = 0, int $componentStockA = 100, int $componentStockB = 100): array
    {
        $assembly = $this->createProduct([
            'name' => 'Assembly Product',
            'type' => 'assembly',
            'stock' => $assemblyStock,
        ]);
        $compA = $this->createProduct(['name' => 'Component A', 'stock' => $componentStockA]);
        $compB = $this->createProduct(['name' => 'Component B', 'stock' => $componentStockB]);

        ProductComponent::create([
            'parent_product_id' => $assembly->id,
            'component_product_id' => $compA->id,
            'quantity' => 2,
            'sort_order' => 0,
        ]);
        ProductComponent::create([
            'parent_product_id' => $assembly->id,
            'component_product_id' => $compB->id,
            'quantity' => 3,
            'sort_order' => 1,
        ]);

        return ['assembly' => $assembly, 'compA' => $compA, 'compB' => $compB];
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_work_orders_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('work-orders.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('WorkOrders/Index')
            ->has('workOrders')
        );
    }

    // ==================== CREATE FORM TESTS ====================

    public function test_admin_can_view_create_work_order_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('work-orders.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('WorkOrders/Create')
            ->has('assemblyProducts')
        );
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_work_order_for_assembly_product(): void
    {
        $data = $this->createAssemblyWithComponents();

        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.store'), [
                'product_id' => $data['assembly']->id,
                'quantity' => 5,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Work order created successfully.');

        $this->assertDatabaseHas('work_orders', [
            'organization_id' => $this->organization->id,
            'product_id' => $data['assembly']->id,
            'quantity' => 5,
            'status' => 'draft',
        ]);

        // Work order items should be auto-populated from BOM
        $workOrder = WorkOrder::where('product_id', $data['assembly']->id)->first();
        $this->assertNotNull($workOrder);
        $this->assertEquals(2, $workOrder->items()->count());

        // Check quantities are multiplied by work order quantity
        $this->assertDatabaseHas('work_order_items', [
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compA']->id,
            'quantity_required' => '10.00', // 2 * 5
        ]);
        $this->assertDatabaseHas('work_order_items', [
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compB']->id,
            'quantity_required' => '15.00', // 3 * 5
        ]);
    }

    public function test_cannot_create_work_order_for_non_assembly_product(): void
    {
        $standard = $this->createProduct(['name' => 'Standard Product', 'type' => 'standard']);

        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.store'), [
                'product_id' => $standard->id,
                'quantity' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('product_id');
    }

    // ==================== SHOW TESTS ====================

    public function test_can_view_work_order_details(): void
    {
        $data = $this->createAssemblyWithComponents();

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $data['assembly']->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->organization->id),
            'quantity' => 5,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('work-orders.show', $workOrder));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('WorkOrders/Show')
            ->has('workOrder')
        );
    }

    // ==================== START TESTS ====================

    public function test_can_start_a_work_order(): void
    {
        $data = $this->createAssemblyWithComponents(0, 100, 100);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $data['assembly']->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->organization->id),
            'quantity' => 5,
            'status' => 'draft',
        ]);

        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compA']->id,
            'quantity_required' => 10,
            'quantity_consumed' => 0,
        ]);
        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compB']->id,
            'quantity_required' => 15,
            'quantity_consumed' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.start', $workOrder));

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $response->assertSessionHas('success', 'Work order started successfully.');

        $workOrder->refresh();
        $this->assertEquals('in_progress', $workOrder->status);
        $this->assertNotNull($workOrder->started_at);
    }

    public function test_cannot_start_work_order_with_insufficient_stock(): void
    {
        $data = $this->createAssemblyWithComponents(0, 5, 100); // compA only has 5, needs 10

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $data['assembly']->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->organization->id),
            'quantity' => 5,
            'status' => 'draft',
        ]);

        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compA']->id,
            'quantity_required' => 10, // needs 10, only 5 available
            'quantity_consumed' => 0,
        ]);
        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compB']->id,
            'quantity_required' => 15,
            'quantity_consumed' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.start', $workOrder));

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $response->assertSessionHas('error');

        $workOrder->refresh();
        $this->assertEquals('draft', $workOrder->status);
    }

    // ==================== COMPLETE TESTS ====================

    public function test_can_complete_a_work_order(): void
    {
        $data = $this->createAssemblyWithComponents(0, 100, 100);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $data['assembly']->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->organization->id),
            'quantity' => 5,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compA']->id,
            'quantity_required' => 10,
            'quantity_consumed' => 0,
        ]);
        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compB']->id,
            'quantity_required' => 15,
            'quantity_consumed' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.complete', $workOrder));

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $response->assertSessionHas('success');

        $workOrder->refresh();
        $this->assertEquals('completed', $workOrder->status);
        $this->assertEquals(5, $workOrder->quantity_produced);
        $this->assertNotNull($workOrder->completed_at);

        // Component stock should be decremented
        $data['compA']->refresh();
        $data['compB']->refresh();
        $this->assertEquals(90, $data['compA']->stock); // 100 - 10
        $this->assertEquals(85, $data['compB']->stock); // 100 - 15

        // Assembly stock should be incremented
        $data['assembly']->refresh();
        $this->assertEquals(5, $data['assembly']->stock); // 0 + 5
    }

    // ==================== CANCEL TESTS ====================

    public function test_can_cancel_a_pending_work_order(): void
    {
        $data = $this->createAssemblyWithComponents();

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $data['assembly']->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->organization->id),
            'quantity' => 5,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.cancel', $workOrder));

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $response->assertSessionHas('success', 'Work order has been cancelled.');

        $workOrder->refresh();
        $this->assertEquals('cancelled', $workOrder->status);
    }

    public function test_can_cancel_in_progress_work_order_and_restore_consumed_stock(): void
    {
        $data = $this->createAssemblyWithComponents(0, 100, 100);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $data['assembly']->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->organization->id),
            'quantity' => 5,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        // Simulate partially consumed stock
        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compA']->id,
            'quantity_required' => 10,
            'quantity_consumed' => 6,
        ]);
        WorkOrderItem::create([
            'work_order_id' => $workOrder->id,
            'product_id' => $data['compB']->id,
            'quantity_required' => 15,
            'quantity_consumed' => 9,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.cancel', $workOrder));

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $response->assertSessionHas('success', 'Work order has been cancelled.');

        $workOrder->refresh();
        $this->assertEquals('cancelled', $workOrder->status);

        // Stock should be restored
        $data['compA']->refresh();
        $data['compB']->refresh();
        $this->assertEquals(106, $data['compA']->stock); // 100 + 6 restored
        $this->assertEquals(109, $data['compB']->stock); // 100 + 9 restored

        // quantity_consumed should be reset
        $workOrder->load('items');
        foreach ($workOrder->items as $item) {
            $this->assertEquals('0.00', $item->quantity_consumed);
        }
    }

    // ==================== STATUS TRANSITION TESTS ====================

    public function test_cannot_start_a_completed_work_order(): void
    {
        $data = $this->createAssemblyWithComponents();

        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $data['assembly']->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->organization->id),
            'quantity' => 5,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.start', $workOrder));

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $response->assertSessionHas('error');

        $workOrder->refresh();
        $this->assertEquals('completed', $workOrder->status);
    }

    // ==================== AUTO-GENERATE WO NUMBER TESTS ====================

    public function test_work_order_auto_generates_wo_number(): void
    {
        $data = $this->createAssemblyWithComponents();

        $this->actingAs($this->admin)
            ->post(route('work-orders.store'), [
                'product_id' => $data['assembly']->id,
                'quantity' => 1,
            ]);

        $workOrder = WorkOrder::where('organization_id', $this->organization->id)->first();
        $this->assertNotNull($workOrder);
        $this->assertMatchesRegularExpression('/^WO-\d{8}-\d{4}$/', $workOrder->work_order_number);
    }

    // ==================== AUTHORIZATION TESTS ====================

    public function test_unauthorized_user_cannot_access_work_orders(): void
    {
        $response = $this->actingAs($this->viewer)
            ->get(route('work-orders.index'));

        $response->assertStatus(403);
    }
}
