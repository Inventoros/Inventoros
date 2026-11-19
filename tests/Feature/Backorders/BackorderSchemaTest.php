<?php

declare(strict_types=1);

namespace Tests\Feature\Backorders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackorderSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function organization(): Organization
    {
        return Organization::create([
            'name' => 'BO Org',
            'email' => 'bo@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    public function test_product_allow_backorder_defaults_false_and_casts_bool(): void
    {
        $product = Product::create([
            'organization_id' => $this->organization()->id,
            'sku' => 'BO-1',
            'name' => 'BO Product',
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => 5,
            'is_active' => true,
        ]);

        $fresh = $product->fresh();
        $this->assertIsBool($fresh->allow_backorder);
        $this->assertFalse($fresh->allow_backorder);

        $product->update(['allow_backorder' => true]);
        $this->assertTrue($product->fresh()->allow_backorder);
    }

    public function test_order_item_exposes_quantity_fulfilled(): void
    {
        $item = new OrderItem(['quantity' => 5, 'quantity_backordered' => 2]);

        $this->assertSame(3, $item->quantity_fulfilled);
    }

    public function test_order_backordered_at_casts_and_outstanding_flag(): void
    {
        $order = Order::create([
            'organization_id' => $this->organization()->id,
            'order_number' => 'ORD-BO-1',
            'status' => 'pending',
            'currency' => 'USD',
            'order_date' => now(),
            'backordered_at' => now(),
        ]);

        $this->assertInstanceOf(Carbon::class, $order->fresh()->backordered_at);

        $order->items()->create([
            'product_name' => 'BO Product',
            'quantity' => 5,
            'quantity_backordered' => 2,
            'unit_price' => 10.00,
            'subtotal' => 50.00,
            'total' => 50.00,
        ]);

        $this->assertTrue($order->fresh()->hasOutstandingBackorder());

        $order->items()->update(['quantity_backordered' => 0]);

        $this->assertFalse($order->fresh()->hasOutstandingBackorder());
    }
}
