<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A return refunds what the customer actually paid for the goods: the unit
 * price net of the line's own discount and of the line's prorated share of
 * the order-level discount. The portal and the staff screen both go through
 * ReturnOrderService, so they must agree.
 *
 * Fixture: line A is 2 x 50.00 with a 10% line discount (net 90.00), line B
 * is 1 x 30.00 (net 30.00), and the order carries a further 12.00 order-level
 * discount (10% of the 120.00 net). Prorated by net value, A absorbs 9.00 and
 * B 3.00, so A was paid 81.00 (40.50 a unit) and B 27.00.
 */
class ReturnRefundDiscountTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    /**
     * @return array{0: \App\Models\Auth\Organization, 1: \App\Models\CustomerContact, 2: Order}
     */
    private function discountedOrder(): array
    {
        $org = $this->makeOrganization('Discount Co');
        $customer = $this->makeCustomer($org, 'Buyer D');
        $contact = $this->makeContact($customer, 'd@buyer.test');
        $a = $this->makeProduct($org, 'SKU-A');
        $b = $this->makeProduct($org, 'SKU-B');

        $order = $this->makeOrder($customer, 'ORD-D-1', OrderStatus::DELIVERED, [
            ['product' => $a, 'quantity' => 2, 'unit_price' => 50],
            ['product' => $b, 'quantity' => 1, 'unit_price' => 30],
        ]);

        $lineA = $order->items()->where('product_id', $a->id)->firstOrFail();
        $lineA->forceFill(['discount_type' => 'percent', 'discount_value' => 10, 'discount_amount' => 10, 'total' => 90])->save();

        $order->forceFill([
            'subtotal' => 130,
            'discount_type' => 'fixed',
            'discount_value' => 12,
            'discount_amount' => 22,
            'total' => 108,
        ])->save();

        return [$org, $contact, $order->fresh('items')];
    }

    public function test_portal_return_refunds_the_discounted_price_paid(): void
    {
        [$org, $contact, $order] = $this->discountedOrder();
        [$lineA, $lineB] = [$order->items[0], $order->items[1]];

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'orders/'.$order->id.'/return'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.items.0.paid_net', '81.00')
                ->where('order.items.1.paid_net', '27.00'));

        $this->actingAs($contact, 'customer')
            ->post($this->portalUrl($org, 'orders/'.$order->id.'/return'), [
                'type' => 'return',
                'reason' => 'Changed my mind',
                'items' => [
                    ['order_item_id' => $lineA->id, 'quantity' => 1, 'condition' => 'new'],
                    ['order_item_id' => $lineB->id, 'quantity' => 1, 'condition' => 'new'],
                ],
            ])->assertRedirect();

        // 40.50 + 27.00, not the 80.00 list price.
        $this->assertSame('67.50', (string) ReturnOrder::sole()->refund_amount);
    }

    public function test_staff_return_uses_the_same_refund_calculation(): void
    {
        [$org, , $order] = $this->discountedOrder();
        $admin = $this->makeStaff($org, 'admin@discount.test');
        $lineA = $order->items[0];

        $this->actingAs($admin)
            ->get(route('returns.create', ['order_id' => $order->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('paidNets.'.$lineA->id, '81.00'));

        $this->actingAs($admin)
            ->post(route('returns.store'), [
                'order_id' => $order->id,
                'type' => 'return',
                'reason' => 'Defective',
                'items' => [
                    ['order_item_id' => $lineA->id, 'product_id' => $lineA->product_id, 'quantity' => 2, 'condition' => 'new', 'restock' => true],
                ],
            ])->assertRedirect();

        $this->assertSame('81.00', (string) ReturnOrder::sole()->refund_amount);
    }

    public function test_an_undiscounted_order_still_refunds_the_unit_price(): void
    {
        $org = $this->makeOrganization('Plain Co');
        $customer = $this->makeCustomer($org, 'Buyer P');
        $contact = $this->makeContact($customer, 'p@buyer.test');
        $order = $this->makeOrder($customer, 'ORD-P-1', OrderStatus::DELIVERED, [
            ['product' => $this->makeProduct($org, 'SKU-P'), 'quantity' => 3, 'unit_price' => 19.99],
        ]);

        $this->actingAs($contact, 'customer')
            ->post($this->portalUrl($org, 'orders/'.$order->id.'/return'), [
                'type' => 'return',
                'reason' => 'Wrong size',
                'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 2, 'condition' => 'new']],
            ])->assertRedirect();

        $this->assertSame('39.98', (string) ReturnOrder::sole()->refund_amount);
    }
}
