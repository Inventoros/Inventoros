<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkOrderConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $organization;

    protected ProductCategory $category;

    protected ProductLocation $location;

    protected Product $assembly;

    protected Product $component;

    protected WorkOrder $workOrder;

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

        $this->createSystemRoles();

        // Assembly product starts with 0 stock; single component with 50 stock.
        $this->assembly = $this->createProduct([
            'name' => 'Assembly Product',
            'type' => 'assembly',
            'stock' => 0,
        ]);

        $this->component = $this->createProduct([
            'name' => 'Component A',
            'stock' => 50,
        ]);

        $this->workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->assembly->id,
            'created_by' => $this->admin->id,
            'work_order_number' => WorkOrder::generateWorkOrderNumber($this->organization->id),
            'quantity' => 5,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        WorkOrderItem::create([
            'work_order_id' => $this->workOrder->id,
            'product_id' => $this->component->id,
            'quantity_required' => 10,
            'quantity_consumed' => 0,
        ]);
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

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-'.uniqid(),
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

    public function test_double_complete_consumes_and_produces_once(): void
    {
        $this->actingAs($this->admin)
            ->post(route('work-orders.complete', $this->workOrder));

        $componentAfter = $this->component->fresh()->stock; // 50 - 10 = 40
        $assemblyAfter = $this->assembly->fresh()->stock;   // 0 + 5 = 5

        $this->assertSame(40, $componentAfter);
        $this->assertSame(5, $assemblyAfter);

        // Replay — second complete must be rejected (status now 'completed'),
        // leaving stock untouched.
        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.complete', $this->workOrder));

        $response->assertSessionHas('error');
        $this->assertSame($componentAfter, $this->component->fresh()->stock);
        $this->assertSame($assemblyAfter, $this->assembly->fresh()->stock);
    }

    public function test_cancel_after_complete_does_not_restore_stock(): void
    {
        $this->actingAs($this->admin)
            ->post(route('work-orders.complete', $this->workOrder));

        $componentAfter = $this->component->fresh()->stock; // 40

        // A completed work order cannot be cancelled; consumed stock must not
        // be restored.
        $response = $this->actingAs($this->admin)
            ->post(route('work-orders.cancel', $this->workOrder));

        $response->assertSessionHas('error');
        $this->assertSame($componentAfter, $this->component->fresh()->stock);
    }

    public function test_complete_is_rejected_when_a_component_would_go_negative(): void
    {
        Mail::fake();
        Notification::fake();

        $this->actingAs($this->admin);

        // Component drained below the required 10 while the WO sat in progress.
        $this->component->update(['stock' => 3]);

        $response = $this->post(route('work-orders.complete', $this->workOrder));

        $response->assertSessionHas('error');

        // No negative stock, no phantom production, WO stays in progress.
        $this->assertSame(3, $this->component->fresh()->stock);
        $this->assertDatabaseHas('work_orders', [
            'id' => $this->workOrder->id,
            'status' => 'in_progress',
        ]);
    }
}
