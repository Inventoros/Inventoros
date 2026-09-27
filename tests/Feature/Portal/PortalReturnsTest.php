<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Order\ReturnOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalReturnsTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    public function test_contact_requests_a_return_for_a_delivered_order(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $product = $this->makeProduct($org, 'SKU-1', stock: 50);
        $order = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::DELIVERED, [
            ['product' => $product, 'quantity' => 4, 'unit_price' => 10],
        ]);
        $item = $order->items()->first();

        $admin = $this->makeStaff($org, 'admin@example.test');
        $returnsClerk = $this->makeStaffWithPermissions($org, 'returns@example.test', ['manage_returns']);
        $salesRep = $this->makeStaffWithPermissions($org, 'sales@example.test', ['view_orders']);

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'orders/'.$order->id.'/return'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Returns/Create')
                ->where('order.items.0.returnable_quantity', 4));

        $response = $this->actingAs($contact, 'customer')
            ->post($this->portalUrl($org, 'orders/'.$order->id.'/return'), [
                'type' => 'return',
                'reason' => 'Two units arrived cracked',
                'notes' => 'Box was crushed',
                'items' => [
                    ['order_item_id' => $item->id, 'quantity' => 2, 'condition' => 'damaged'],
                ],
            ]);

        $return = ReturnOrder::firstOrFail();
        $response->assertRedirect($this->portalUrl($org, 'returns/'.$return->id));

        $this->assertSame('pending', $return->status);
        $this->assertSame($org->id, $return->organization_id);
        $this->assertSame($order->id, $return->order_id);
        $this->assertSame('20.00', (string) $return->refund_amount);
        $returnItem = $return->items()->first();
        $this->assertSame(2, (int) $returnItem->quantity);
        $this->assertSame($product->id, $returnItem->product_id);
        $this->assertSame('damaged', $returnItem->condition);
        $this->assertFalse((bool) $returnItem->restock);

        // Stock is untouched until staff receive the return.
        $this->assertSame(50, (int) $product->fresh()->stock);

        // Staff who handle returns are notified; others are not.
        $notified = Notification::where('type', 'portal_return_requested')->pluck('user_id')->all();
        sort($notified);
        $this->assertSame([$admin->id, $returnsClerk->id], $notified);
        $this->assertNotContains($salesRep->id, $notified);
        $this->assertSame(
            route('returns.show', $return->id),
            Notification::where('type', 'portal_return_requested')->value('action_url'),
        );

        $this->assertTrue(ActivityLog::where('action', 'portal.return_requested')
            ->where('subject_id', $return->id)->exists());
    }

    public function test_returns_cannot_exceed_the_remaining_quantity(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $product = $this->makeProduct($org, 'SKU-1');
        $order = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::DELIVERED, [['product' => $product, 'quantity' => 2]]);
        $item = $order->items()->first();

        $payload = fn (int $qty) => [
            'type' => 'return',
            'reason' => 'Not needed',
            'items' => [['order_item_id' => $item->id, 'quantity' => $qty, 'condition' => 'new']],
        ];

        $this->actingAs($contact, 'customer')
            ->post($this->portalUrl($org, 'orders/'.$order->id.'/return'), $payload(3))
            ->assertSessionHasErrors('items.0.quantity');

        $this->post($this->portalUrl($org, 'orders/'.$order->id.'/return'), $payload(2))
            ->assertSessionHasNoErrors();

        $this->post($this->portalUrl($org, 'orders/'.$order->id.'/return'), $payload(1))
            ->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(1, ReturnOrder::count());
    }

    public function test_returns_are_only_for_delivered_orders(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $product = $this->makeProduct($org, 'SKU-1');
        $order = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::SHIPPED, [['product' => $product, 'quantity' => 2]]);

        $this->actingAs($contact, 'customer');
        $this->get($this->portalUrl($org, 'orders/'.$order->id.'/return'))->assertRedirect($this->portalUrl($org, 'orders/'.$order->id));
        $this->post($this->portalUrl($org, 'orders/'.$order->id.'/return'), [
            'type' => 'return',
            'reason' => 'x',
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1, 'condition' => 'new']],
        ])->assertRedirect($this->portalUrl($org, 'orders/'.$order->id));

        $this->assertSame(0, ReturnOrder::count());
    }

    public function test_a_line_from_another_order_cannot_be_returned(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $product = $this->makeProduct($org, 'SKU-1');
        $mine = $this->makeOrder($customer, 'ORD-A-1', OrderStatus::DELIVERED, [['product' => $product, 'quantity' => 2]]);
        $theirs = $this->makeOrder($this->makeCustomer($org, 'Buyer B'), 'ORD-B-1', OrderStatus::DELIVERED, [['product' => $product, 'quantity' => 9]]);

        $this->actingAs($contact, 'customer')
            ->post($this->portalUrl($org, 'orders/'.$mine->id.'/return'), [
                'type' => 'return',
                'reason' => 'x',
                'items' => [['order_item_id' => $theirs->items()->first()->id, 'quantity' => 5, 'condition' => 'new']],
            ])
            ->assertSessionHasErrors('items.0.order_item_id');

        $this->post($this->portalUrl($org, 'orders/'.$theirs->id.'/return'), [
            'type' => 'return',
            'reason' => 'x',
            'items' => [['order_item_id' => $theirs->items()->first()->id, 'quantity' => 1, 'condition' => 'new']],
        ])->assertNotFound();

        $this->assertSame(0, ReturnOrder::count());
    }

    public function test_return_list_and_detail_are_scoped_to_the_customer_and_org(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $customer = $this->makeCustomer($orgA, 'Buyer A');
        $contact = $this->makeContact($customer, 'a@buyer.test');
        $product = $this->makeProduct($orgA, 'SKU-1');

        $mine = $this->makeReturn($this->makeOrder($customer, 'ORD-A-1', OrderStatus::DELIVERED, [['product' => $product, 'quantity' => 1]]), 'RET-A');
        $sibling = $this->makeReturn($this->makeOrder($this->makeCustomer($orgA, 'Buyer B'), 'ORD-B-1', OrderStatus::DELIVERED, [['product' => $product, 'quantity' => 1]]), 'RET-B');
        $productB = $this->makeProduct($orgB, 'SKU-B');
        $foreign = $this->makeReturn($this->makeOrder($this->makeCustomer($orgB, 'Buyer C'), 'ORD-C-1', OrderStatus::DELIVERED, [['product' => $productB, 'quantity' => 1]]), 'RET-C');

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($orgA, 'returns'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Returns/Index')
                ->has('returns.data', 1)
                ->where('returns.data.0.return_number', 'RET-A'));

        $this->get($this->portalUrl($orgA, 'returns/'.$mine->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Returns/Show')
                ->where('returnOrder.return_number', 'RET-A')
                ->where('returnOrder.status', 'pending'));

        $this->get($this->portalUrl($orgA, 'returns/'.$sibling->id))->assertNotFound();
        $this->get($this->portalUrl($orgA, 'returns/'.$foreign->id))->assertNotFound();
    }

    private function makeReturn($order, string $number): ReturnOrder
    {
        $item = $order->items()->first();

        $return = ReturnOrder::create([
            'organization_id' => $order->organization_id,
            'order_id' => $order->id,
            'return_number' => $number,
            'type' => 'return',
            'status' => 'pending',
            'reason' => 'Because',
            'refund_amount' => 10,
        ]);

        $return->items()->create([
            'order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'quantity' => 1,
            'condition' => 'new',
            'restock' => true,
        ]);

        return $return;
    }
}
