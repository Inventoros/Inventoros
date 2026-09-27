<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderStatus;
use App\Models\Inventory\Product;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Once part of an order has shipped, the web and REST cancel and delete paths
 * must refuse instead of restocking goods that already left.
 */
class ShippedOrderGuardsHttpTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
    }

    private function partiallyShippedOrder()
    {
        $order = $this->makeOrder();
        $line = $order->items->firstWhere('product_id', $this->widget->id);
        $service = app(ShipmentService::class);
        $shipment = $service->create($order, [
            'carrier' => 'manual', 'items' => [['order_item_id' => $line->id, 'quantity' => 1]],
        ], $this->admin);
        $service->markShipped($shipment, $this->admin);

        return $order->fresh('items');
    }

    public function test_web_cancel_is_refused_with_a_flash_and_no_restock(): void
    {
        $order = $this->partiallyShippedOrder();
        $stock = Product::find($this->widget->id)->stock;

        $this->actingAs($this->admin)
            ->from(route('orders.edit', $order))
            ->put(route('orders.update', $order), [
                'customer_name' => $order->customer_name,
                'status' => 'cancelled',
                'order_date' => now()->toDateString(),
                'items' => $order->items->map(fn ($i) => [
                    'product_id' => $i->product_id, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price,
                ])->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
        $this->assertSame($stock, Product::find($this->widget->id)->stock);
    }

    public function test_web_delete_is_refused_with_a_flash(): void
    {
        $order = $this->partiallyShippedOrder();

        $this->actingAs($this->admin)
            ->from(route('orders.show', $order))
            ->delete(route('orders.destroy', $order))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($order);
    }

    public function test_api_delete_is_refused_with_a_422(): void
    {
        $order = $this->partiallyShippedOrder();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/orders/{$order->id}")
            ->assertStatus(422);

        $this->assertNotSoftDeleted($order);
    }
}
