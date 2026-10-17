<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order\ReturnOrder;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentItem;
use App\Services\ReturnOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

final class ReturnShippedQuantityTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    private function fixtures(OrderStatus $status = OrderStatus::PROCESSING): array
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Shipped returns');
        $customer = $this->makeCustomer($org, 'Buyer');
        $a = $this->makeProduct($org, 'SHIP-A');
        $b = $this->makeProduct($org, 'SHIP-B');
        $order = $this->makeOrder($customer, 'SHIP-ORDER', $status, [
            ['product' => $a, 'quantity' => 1], ['product' => $b, 'quantity' => 5],
        ]);

        return [$org, $customer, $order, $order->items()->orderBy('id')->get(), $b];
    }

    private function shipment($order, array $lines, ShipmentStatus $status = ShipmentStatus::SHIPPED): void
    {
        $shipment = Shipment::create(['organization_id' => $order->organization_id, 'order_id' => $order->id, 'status' => $status, 'carrier' => 'manual']);
        foreach ($lines as [$line, $quantity]) {
            ShipmentItem::create(['shipment_id' => $shipment->id, 'order_item_id' => $line->id, 'quantity' => $quantity]);
        }
    }

    private function requestReturn($order, array $lines): ReturnOrder
    {
        return app(ReturnOrderService::class)->create($order->organization_id, null, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Returned goods',
            'items' => array_map(fn ($line) => ['order_item_id' => $line[0]->id, 'quantity' => $line[1], 'condition' => 'new', 'restock' => true], $lines),
        ]);
    }

    private function assertQuantityRejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Unshipped quantity was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.quantity', $e->errors());
        }
    }

    private function oldApprovedReturn($order, $line, int $quantity): ReturnOrder
    {
        $return = ReturnOrder::create(['organization_id' => $order->organization_id, 'order_id' => $order->id,
            'return_number' => ReturnOrder::generateReturnNumber($order->organization_id), 'type' => 'return',
            'status' => 'approved', 'reason' => 'Previously accepted return', 'refund_amount' => '10.00']);
        $return->items()->create(['order_item_id' => $line->id, 'product_id' => $line->product_id,
            'quantity' => $quantity, 'condition' => 'new', 'restock' => true]);

        return $return;
    }

    public function test_shipping_one_line_does_not_allow_returning_another_unshipped_line(): void
    {
        [$org, $customer, $order, $lines] = $this->fixtures();
        $this->shipment($order, [[$lines[0], 1]]);
        $this->assertQuantityRejected(fn () => $this->requestReturn($order, [[$lines[1], 1]]));
        $this->assertSame(0, ReturnOrder::count());
    }

    public function test_partial_shipments_cap_each_line_and_pending_returns_reserve_that_cap(): void
    {
        [$org, $customer, $order, $lines] = $this->fixtures();
        $this->shipment($order, [[$lines[1], 2]]);
        $this->assertQuantityRejected(fn () => $this->requestReturn($order, [[$lines[1], 3]]));
        $this->requestReturn($order, [[$lines[1], 1]]);
        $this->assertQuantityRejected(fn () => $this->requestReturn($order, [[$lines[1], 2]]));
        $this->requestReturn($order, [[$lines[1], 1]]);
        $this->assertSame(2, ReturnOrder::count());
    }

    public function test_duplicate_lines_cannot_exceed_the_shipped_cap(): void
    {
        [$org, $customer, $order, $lines] = $this->fixtures();
        $this->shipment($order, [[$lines[1], 1]]);
        try {
            $this->requestReturn($order, [[$lines[1], 1], [$lines[1], 1]]);
            $this->fail('Repeated lines exceeded the shipped quantity.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.1.quantity', $e->errors());
        }
        $this->assertSame(0, ReturnOrder::count());
    }

    public function test_unshipped_and_cancelled_shipments_do_not_count_even_if_order_is_marked_shipped(): void
    {
        [$org, $customer, $order, $lines] = $this->fixtures(OrderStatus::SHIPPED);
        foreach ([ShipmentStatus::PENDING, ShipmentStatus::LABEL_CREATED, ShipmentStatus::CANCELLED] as $status) {
            $this->shipment($order, [[$lines[1], 1]], $status);
        }
        $this->assertQuantityRejected(fn () => $this->requestReturn($order, [[$lines[1], 1]]));
    }

    public function test_legacy_shipped_order_without_shipment_records_remains_returnable(): void
    {
        [$org, $customer, $order, $lines] = $this->fixtures(OrderStatus::SHIPPED);
        $return = $this->requestReturn($order, [[$lines[1], 5]]);
        $this->assertSame(5, $return->items()->first()->quantity);
    }

    public function test_receive_rejects_an_old_return_for_goods_that_never_shipped(): void
    {
        [$org, $customer, $order, $lines, $product] = $this->fixtures();
        $this->shipment($order, [[$lines[0], 1]]);
        $return = $this->oldApprovedReturn($order, $lines[1], 1);
        $stock = $product->fresh()->stock;
        $this->assertQuantityRejected(fn () => app(ReturnOrderService::class)->receive($return));
        $this->assertSame($stock, $product->fresh()->stock);
        $this->assertSame('approved', $return->fresh()->status);
    }

    public function test_receiving_separate_old_returns_cannot_restock_more_than_shipped(): void
    {
        [$org, $customer, $order, $lines, $product] = $this->fixtures();
        $this->shipment($order, [[$lines[1], 1]]);
        $this->makeStaff($org, 'ledger@returns.test');
        $first = $this->oldApprovedReturn($order, $lines[1], 1);
        $second = $this->oldApprovedReturn($order, $lines[1], 1);
        $stock = $product->fresh()->stock;
        app(ReturnOrderService::class)->receive($first);
        $this->assertQuantityRejected(fn () => app(ReturnOrderService::class)->receive($second));
        $this->assertSame($stock + 1, $product->fresh()->stock);
    }

    public function test_staff_and_portal_forms_show_the_shipped_cap(): void
    {
        [$org, $customer, $order, $lines] = $this->fixtures(OrderStatus::DELIVERED);
        $this->shipment($order, [[$lines[1], 2]], ShipmentStatus::DELIVERED);
        $admin = $this->makeStaff($org, 'forms@returns.test');
        $this->actingAs($admin)->get(route('returns.create', ['order_id' => $order->id]))
            ->assertOk()->assertInertia(fn ($page) => $page
            ->where('returnableQuantities.'.$lines[0]->id, 0)
            ->where('returnableQuantities.'.$lines[1]->id, 2));
        $contact = $this->makeContact($customer, 'buyer@returns.test');
        $this->actingAs($contact, 'customer')->get($this->portalUrl($org, 'orders/'.$order->id.'/return'))
            ->assertOk()->assertInertia(fn ($page) => $page
            ->where('order.items.0.returnable_quantity', 0)
            ->where('order.items.1.returnable_quantity', 2));
        $this->post($this->portalUrl($org, 'orders/'.$order->id.'/return'), [
            'type' => 'return', 'reason' => 'Not sent',
            'items' => [['order_item_id' => $lines[0]->id, 'quantity' => 1, 'condition' => 'new']],
        ])->assertSessionHasErrors('items.0.quantity');
    }
}
