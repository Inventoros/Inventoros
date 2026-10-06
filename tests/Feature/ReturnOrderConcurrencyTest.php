<?php

namespace Tests\Feature;

use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\ReturnOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnOrderConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $organization;

    protected Product $product;

    protected ProductCategory $category;

    protected ProductLocation $location;

    protected ReturnOrder $return;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-PROD-001',
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

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => [
                    'view_orders',
                    'create_orders',
                    'edit_orders',
                    'manage_returns',
                    'manage_stock',
                ],
            ]
        );
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $order = Order::create([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-'.now()->format('Ymd').'-0001',
            'source' => 'manual',
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_address' => '123 Customer St',
            'status' => 'delivered',
            'subtotal' => 499.95,
            'tax' => 0,
            'shipping' => 0,
            'total' => 499.95,
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'quantity' => 5,
            'unit_price' => 99.99,
            'subtotal' => 499.95,
            'tax' => 0,
            'total' => 499.95,
        ]);

        $this->return = ReturnOrder::create([
            'organization_id' => $this->organization->id,
            'order_id' => $order->id,
            'return_number' => ReturnOrder::generateReturnNumber($this->organization->id),
            'type' => 'return',
            'status' => 'approved',
            'reason' => 'Defective product',
            'notes' => 'Restock test',
            'refund_amount' => 499.95,
        ]);

        ReturnOrderItem::create([
            'return_order_id' => $this->return->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'condition' => 'new',
            'restock' => true,
        ]);

        $this->return = $this->return->fresh(['items']);
    }

    public function test_double_receive_restocks_once(): void
    {
        // approved return, one item: quantity 5, restock true, product stock 100.
        $this->actingAs($this->admin)->post(route('returns.receive', $this->return));
        $this->assertSame(105, $this->product->fresh()->stock);

        $response = $this->actingAs($this->admin)->post(route('returns.receive', $this->return));
        $response->assertSessionHas('error');
        $this->assertSame(105, $this->product->fresh()->stock); // not double-restocked
    }

    public function test_stale_approval_and_rejection_cannot_change_a_received_return(): void
    {
        $this->actingAs($this->admin);
        $this->return->update(['status' => 'pending']);
        $stale = $this->return->fresh();
        $service = app(ReturnOrderService::class);
        $approved = $service->approve($this->return->fresh(), $this->admin);
        $service->receive($approved, $this->admin);

        foreach (['approve', 'reject'] as $transition) {
            try {
                $service->{$transition}($stale, $this->admin);
                $this->fail('A stale '.$transition.' must not change a received return.');
            } catch (InvalidStateException) {
                // Receiving and every other transition share the return lock.
            }
        }

        $this->assertSame('received', $stale->fresh()->status);
        $this->assertSame(105, $this->product->fresh()->stock);
        $this->assertSame(1, StockAdjustment::where('reference_type', ReturnOrder::class)->where('reference_id', $stale->id)->count());
        $this->assertSame(5, (int) $service->returnedQuantities($stale->order)->sum());
    }
}
