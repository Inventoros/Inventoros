<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\ShippingException;
use App\Models\Inventory\Product;
use App\Models\Shipping\Shipment;
use App\Services\OrderService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentServiceTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
    }

    private function service(): ShipmentService
    {
        return app(ShipmentService::class);
    }

    public function test_manual_shipment_defaults_to_every_remaining_line(): void
    {
        $order = $this->makeOrder();

        $shipment = $this->service()->create($order, [
            'carrier' => 'manual',
            'carrier_name' => 'UPS',
            'tracking_number' => '1Z999AA10123456784',
        ], $this->admin);

        $this->assertSame(ShipmentStatus::PENDING, $shipment->status);
        $this->assertSame($this->organization->id, $shipment->organization_id);
        $this->assertSame($this->warehouse->id, $shipment->warehouse_id);
        $this->assertCount(2, $shipment->items);
        $this->assertSame(5, (int) $shipment->items->sum('quantity'));
    }

    public function test_marking_a_full_shipment_shipped_moves_the_order_to_shipped_without_touching_stock(): void
    {
        $order = $this->makeOrder();
        $stockBefore = Product::find($this->widget->id)->stock;

        $shipment = $this->service()->create($order, ['carrier' => 'manual', 'tracking_number' => 'T1'], $this->admin);
        $this->service()->markShipped($shipment, $this->admin);

        $order->refresh();
        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->fresh()->status);
        $this->assertNotNull($shipment->fresh()->shipped_at);
        $this->assertSame(OrderStatus::SHIPPED, $order->status);
        $this->assertNotNull($order->shipped_at);
        // Stock left at order creation; shipping must not decrement again.
        $this->assertSame($stockBefore, Product::find($this->widget->id)->stock);
    }

    public function test_a_partial_shipment_moves_the_order_to_processing_and_the_last_one_to_shipped(): void
    {
        $order = $this->makeOrder();
        $widgetLine = $order->items->firstWhere('product_id', $this->widget->id);
        $gadgetLine = $order->items->firstWhere('product_id', $this->gadget->id);

        $first = $this->service()->create($order, [
            'carrier' => 'manual',
            'items' => [
                ['order_item_id' => $widgetLine->id, 'quantity' => 2],
            ],
        ], $this->admin);
        $this->service()->markShipped($first, $this->admin);

        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
        $this->assertSame([$widgetLine->id => 1, $gadgetLine->id => 2], $this->service()->remainingQuantities($order->fresh()));

        $second = $this->service()->create($order, ['carrier' => 'manual'], $this->admin);
        $this->assertSame(3, (int) $second->items->sum('quantity'));
        $this->service()->markShipped($second, $this->admin);

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_quantities_beyond_what_remains_are_rejected(): void
    {
        $order = $this->makeOrder();
        $widgetLine = $order->items->firstWhere('product_id', $this->widget->id);

        $this->expectException(ShippingException::class);

        $this->service()->create($order, [
            'carrier' => 'manual',
            'items' => [['order_item_id' => $widgetLine->id, 'quantity' => 4]],
        ], $this->admin);
    }

    public function test_lines_from_another_order_are_rejected(): void
    {
        $order = $this->makeOrder();
        $other = $this->makeOrder();

        $this->expectException(ShippingException::class);

        $this->service()->create($order, [
            'carrier' => 'manual',
            'items' => [['order_item_id' => $other->items->first()->id, 'quantity' => 1]],
        ], $this->admin);
    }

    public function test_a_fully_allocated_order_cannot_get_another_shipment(): void
    {
        $order = $this->makeOrder();
        $this->service()->create($order, ['carrier' => 'manual'], $this->admin);

        $this->expectException(ShippingException::class);
        $this->service()->create($order, ['carrier' => 'manual'], $this->admin);
    }

    public function test_cancelled_orders_cannot_be_shipped(): void
    {
        $order = $this->makeOrder();
        $this->actingAs($this->admin);
        app(OrderService::class)->cancel($order);

        $this->expectException(ShippingException::class);
        $this->service()->create($order->fresh(), ['carrier' => 'manual'], $this->admin);
    }

    public function test_cancelling_a_pending_shipment_frees_its_quantities(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->service()->create($order, ['carrier' => 'manual'], $this->admin);

        $this->service()->cancel($shipment);

        $this->assertSame(ShipmentStatus::CANCELLED, $shipment->fresh()->status);
        $this->assertSame(5, array_sum($this->service()->remainingQuantities($order->fresh())));
    }

    public function test_a_shipped_shipment_cannot_be_cancelled(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->service()->create($order, ['carrier' => 'manual'], $this->admin);
        $this->service()->markShipped($shipment, $this->admin);

        $this->expectException(ShippingException::class);
        $this->service()->cancel($shipment->fresh());
    }

    public function test_delivery_of_every_shipment_moves_the_order_to_delivered(): void
    {
        $order = $this->makeOrder();
        $widgetLine = $order->items->firstWhere('product_id', $this->widget->id);

        $first = $this->service()->create($order, [
            'carrier' => 'manual', 'items' => [['order_item_id' => $widgetLine->id, 'quantity' => 3]],
        ], $this->admin);
        $second = $this->service()->create($order, ['carrier' => 'manual'], $this->admin);
        $this->service()->markShipped($first, $this->admin);
        $this->service()->markShipped($second, $this->admin);

        $this->service()->applyTrackingStatus($first->fresh(), ShipmentStatus::DELIVERED);
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);

        $this->service()->applyTrackingStatus($second->fresh(), ShipmentStatus::DELIVERED, 'Left at front door');

        $order->refresh();
        $this->assertSame(OrderStatus::DELIVERED, $order->status);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame('Left at front door', $second->fresh()->tracking_status_detail);
        $this->assertNotNull($second->fresh()->delivered_at);
    }

    public function test_an_in_transit_update_on_a_label_only_shipment_marks_it_shipped(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->service()->create($order, ['carrier' => 'manual'], $this->admin);

        $this->service()->applyTrackingStatus($shipment, ShipmentStatus::IN_TRANSIT);

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $shipment->fresh()->status);
        $this->assertNotNull($shipment->fresh()->shipped_at);
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_order_cancel_is_refused_once_part_of_it_has_shipped(): void
    {
        $order = $this->makeOrder();
        $widgetLine = $order->items->firstWhere('product_id', $this->widget->id);
        $shipment = $this->service()->create($order, [
            'carrier' => 'manual', 'items' => [['order_item_id' => $widgetLine->id, 'quantity' => 1]],
        ], $this->admin);
        $this->service()->markShipped($shipment, $this->admin);
        $stock = Product::find($this->widget->id)->stock;

        try {
            app(OrderService::class)->cancel($order->fresh());
            $this->fail('Cancel should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('shipment', strtolower($e->getMessage()));
        }

        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
        $this->assertSame($stock, Product::find($this->widget->id)->stock);
    }

    public function test_deleting_a_partially_shipped_order_does_not_restock_shipped_goods(): void
    {
        $order = $this->makeOrder();
        $widgetLine = $order->items->firstWhere('product_id', $this->widget->id);
        $shipment = $this->service()->create($order, [
            'carrier' => 'manual', 'items' => [['order_item_id' => $widgetLine->id, 'quantity' => 1]],
        ], $this->admin);
        $this->service()->markShipped($shipment, $this->admin);

        $this->expectException(\RuntimeException::class);
        app(OrderService::class)->restockForDeletion($order->fresh());
    }

    public function test_line_items_are_locked_once_shipments_exist_but_unchanged_lines_pass(): void
    {
        $order = $this->makeOrder();
        $this->service()->create($order, ['carrier' => 'manual'], $this->admin);
        $itemIds = $order->items->pluck('id')->sort()->values()->all();

        $same = $order->items->map(fn ($i) => [
            'product_id' => $i->product_id, 'product_variant_id' => null,
            'quantity' => $i->quantity, 'unit_price' => $i->unit_price,
        ])->all();

        \DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), $same));
        $this->assertSame($itemIds, $order->fresh()->items->pluck('id')->sort()->values()->all());

        $this->expectException(\RuntimeException::class);
        $changed = $same;
        $changed[0]['quantity'] = 1;
        \DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), $changed));
    }

    public function test_shipment_rows_are_tenant_scoped(): void
    {
        $order = $this->makeOrder();
        $this->service()->create($order, ['carrier' => 'manual'], $this->admin);

        $other = \App\Models\Auth\Organization::create([
            'name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $stranger = \App\Models\User::create([
            'name' => 'S', 'email' => 's@o.test', 'password' => bcrypt('x'),
            'organization_id' => $other->id, 'role' => 'admin',
        ]);

        $this->actingAs($stranger);
        $this->assertSame(0, Shipment::count());
    }
}
