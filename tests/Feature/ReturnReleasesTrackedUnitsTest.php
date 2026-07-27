<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\OrderItemBatchAllocation;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductSerial;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\Order\ReturnOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Receiving a restockable return must return the specific serials/batches the
 * order line consumed back to available — up to the returned quantity — so the
 * tracked records track the goods coming back, not just products.stock.
 */
class ReturnReleasesTrackedUnitsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Ret Org', 'email' => 'r@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@r.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $role = Role::firstOrCreate(['slug' => 'ret-admin'], [
            'name' => 'Ret Admin', 'is_system' => false, 'permissions' => ['manage_returns'],
        ]);
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
        $this->location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'A', 'code' => 'A', 'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    private function order(Product $product, int $qty): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'delivered', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 10.00]],
        ], $this->admin);
    }

    private function approvedReturn(Order $order, int $qty): ReturnOrder
    {
        $return = ReturnOrder::create([
            'organization_id' => $this->org->id, 'order_id' => $order->id,
            'return_number' => ReturnOrder::generateReturnNumber($this->org->id),
            'type' => 'return', 'status' => 'approved', 'reason' => 'Changed mind', 'refund_amount' => 0,
        ]);
        ReturnOrderItem::create([
            'return_order_id' => $return->id,
            'order_item_id' => $order->items->first()->id,
            'product_id' => $order->items->first()->product_id,
            'quantity' => $qty, 'condition' => 'new', 'restock' => true,
        ]);

        return $return;
    }

    private function serialProduct(): Product
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SER-1', 'name' => 'Serialed',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'location_id' => $this->location->id, 'tracking_type' => 'serial', 'is_active' => true,
        ]);
        for ($i = 1; $i <= 5; $i++) {
            ProductSerial::create([
                'organization_id' => $this->org->id, 'product_id' => $product->id,
                'serial_number' => "SN-{$i}", 'status' => 'available',
            ]);
        }

        return $product;
    }

    private function soldCount(Product $p): int
    {
        return ProductSerial::where('product_id', $p->id)->where('status', 'sold')->count();
    }

    public function test_receiving_a_serial_return_releases_the_serials(): void
    {
        $product = $this->serialProduct();
        $order = $this->order($product, 3);       // 3 sold, stock 2
        $return = $this->approvedReturn($order, 3);

        $this->actingAs($this->admin)->post(route('returns.receive', $return))->assertRedirect();

        // All three come back: stock restored and every serial available again.
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(0, $this->soldCount($product));
        $this->assertSame(5, ProductSerial::where('product_id', $product->id)->where('status', 'available')->count());
    }

    public function test_partial_serial_return_releases_only_the_returned_quantity(): void
    {
        $product = $this->serialProduct();
        $order = $this->order($product, 3);        // 3 sold, stock 2
        $return = $this->approvedReturn($order, 1); // return just one

        $this->actingAs($this->admin)->post(route('returns.receive', $return))->assertRedirect();

        // Only one serial returns; the other two stay allocated to the order.
        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(2, $this->soldCount($product));
    }

    public function test_receiving_a_batch_return_restores_the_batch_quantity(): void
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'BAT-1', 'name' => 'Batched',
            'price' => 10, 'currency' => 'USD', 'stock' => 10, 'min_stock' => 0,
            'location_id' => $this->location->id, 'tracking_type' => 'batch', 'is_active' => true,
        ]);
        $batch = ProductBatch::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'batch_number' => 'B1', 'quantity' => 10, 'expiry_date' => '2026-06-01',
        ]);

        $order = $this->order($product, 4);        // batch 10 -> 6, stock 6
        $this->assertSame(6, (int) $batch->fresh()->quantity);

        $return = $this->approvedReturn($order, 2);
        $this->actingAs($this->admin)->post(route('returns.receive', $return))->assertRedirect();

        // Two units go back to the batch; the allocation keeps the other two.
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(8, (int) $batch->fresh()->quantity);
        $this->assertSame(2, (int) OrderItemBatchAllocation::where('order_item_id', $order->items->first()->id)->sum('quantity'));
    }
}
