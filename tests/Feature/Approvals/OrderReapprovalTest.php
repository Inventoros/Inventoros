<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Notification as InAppNotification;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Shipping\ShipmentService;
use App\Support\ApprovalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * With sales order approval on, an approval covers the terms it was given
 * for. Changing an approved order's lines, prices, discounts or customer
 * (web, REST or GraphQL) sends it back to pending and tells the approvers,
 * the way an approved purchase order goes back for approval when its terms
 * change.
 */
final class OrderReapprovalTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $creator;

    private User $approver;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->creator = $this->makeAdmin($this->org);
        $this->approver = $this->makeAdmin($this->org);
        $this->product = $this->makeProduct($this->org, ['stock' => 50, 'price' => 10]);
        $this->requireOrderApproval();
    }

    private function requireOrderApproval(bool $on = true): void
    {
        $settings = $this->org->fresh()->settings ?? [];
        $settings[ApprovalSettings::KEY] = array_merge($settings[ApprovalSettings::KEY] ?? [], ['orders_enabled' => $on]);
        $this->org->forceFill(['settings' => $settings])->save();
    }

    private function approvedOrder(int $quantity = 2): Order
    {
        $this->actingAs($this->creator);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 10]],
        ], $this->creator);
        app(OrderService::class)->approve($order, $this->approver, 'Looks fine');
        auth()->forgetGuards();

        $order = $order->fresh('items');
        $this->assertSame(OrderApprovalStatus::APPROVED, $order->approval_status);
        InAppNotification::query()->delete();

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function webEdit(Order $order, array $overrides = [], ?int $quantity = null): \Illuminate\Testing\TestResponse
    {
        $order = $order->fresh('items');

        return $this->actingAs($this->creator)->put(route('orders.update', $order), array_merge([
            'customer_name' => $order->customer_name,
            'status' => $order->status->value,
            'order_date' => $order->order_date->toDateString(),
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'quantity' => $quantity ?? $i->quantity,
                'unit_price' => $i->unit_price,
            ])->all(),
        ], $overrides));
    }

    private function assertBackToPending(Order $order): void
    {
        $fresh = $order->fresh();
        $this->assertSame(OrderApprovalStatus::PENDING, $fresh->approval_status);
        $this->assertTrue($fresh->isPendingApproval());
        $this->assertNull($fresh->approved_by);
        $this->assertNull($fresh->approved_at);
    }

    private function assertApproversNotified(): void
    {
        $notified = InAppNotification::where('type', 'approval_requested')->pluck('user_id')->all();
        $this->assertContains($this->approver->id, $notified);
    }

    // ------------------------------------------------------------------
    // Term changes send the order back for approval.
    // ------------------------------------------------------------------

    public function test_a_web_quantity_change_sends_an_approved_order_back_for_approval(): void
    {
        $order = $this->approvedOrder();

        $this->webEdit($order, [], 20)->assertSessionMissing('error');

        $this->assertSame(20, (int) $order->fresh('items')->items->first()->quantity);
        $this->assertBackToPending($order);
        $this->assertApproversNotified();
    }

    public function test_a_web_edit_cannot_change_terms_and_ship_in_one_step(): void
    {
        $order = $this->approvedOrder();

        $this->webEdit($order, ['status' => 'shipped'], 20)->assertSessionHas('error');

        $fresh = $order->fresh('items');
        $this->assertSame(OrderStatus::PENDING, $fresh->status);
        $this->assertSame(2, (int) $fresh->items->first()->quantity);
    }

    public function test_a_web_customer_change_sends_an_approved_order_back_for_approval(): void
    {
        $order = $this->approvedOrder();

        $this->webEdit($order, ['customer_name' => 'Someone Else'])->assertSessionMissing('error');

        $this->assertBackToPending($order);
    }

    public function test_an_api_discount_change_sends_an_approved_order_back_for_approval(): void
    {
        $order = $this->approvedOrder();
        Sanctum::actingAs($this->creator, ['*']);

        $this->putJson("/api/v1/orders/{$order->id}", ['discount_type' => 'percent', 'discount_value' => 90])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'pending');

        $this->assertBackToPending($order);
        $this->assertApproversNotified();

        // And it cannot ship until it is approved again.
        $this->putJson("/api/v1/orders/{$order->id}", ['status' => 'shipped'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'approval_pending');
    }

    public function test_an_api_customer_change_sends_an_approved_order_back_for_approval(): void
    {
        $order = $this->approvedOrder();
        Sanctum::actingAs($this->creator, ['*']);

        $this->putJson("/api/v1/orders/{$order->id}", ['customer_address' => '1 Elsewhere Rd'])->assertOk();

        $this->assertBackToPending($order);
    }

    public function test_a_graphql_discount_change_sends_an_approved_order_back_for_approval(): void
    {
        $order = $this->approvedOrder();
        Sanctum::actingAs($this->creator, ['*']);

        $response = $this->postJson('/graphql', [
            'query' => sprintf('mutation { updateOrder(id: %d, discount_type: "fixed", discount_value: 15) { id } }', $order->id),
        ]);

        $this->assertEmpty($response->json('errors'));
        $this->assertBackToPending($order);
        $this->assertApproversNotified();
    }

    public function test_a_graphql_customer_change_sends_an_approved_order_back_for_approval(): void
    {
        $order = $this->approvedOrder();
        Sanctum::actingAs($this->creator, ['*']);

        $response = $this->postJson('/graphql', [
            'query' => sprintf('mutation { updateOrder(id: %d, customer_name: "Someone Else") { id } }', $order->id),
        ]);

        $this->assertEmpty($response->json('errors'));
        $this->assertBackToPending($order);
    }

    public function test_a_line_edit_on_a_rejected_order_that_is_still_open_needs_approval_again(): void
    {
        // A row rejected before rejection always cancelled the order.
        $order = $this->approvedOrder();
        DB::table('orders')->where('id', $order->id)->update(['approval_status' => 'rejected']);

        $this->webEdit($order, [], 3)->assertSessionMissing('error');

        $this->assertBackToPending($order);
    }

    // ------------------------------------------------------------------
    // Edits that leave the terms alone keep the approval.
    // ------------------------------------------------------------------

    public function test_a_notes_or_status_edit_keeps_the_approval(): void
    {
        $order = $this->approvedOrder();

        $this->webEdit($order, ['notes' => 'Ring the bell', 'status' => 'processing'])->assertSessionMissing('error');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::PROCESSING, $fresh->status);
        $this->assertSame(OrderApprovalStatus::APPROVED, $fresh->approval_status);
        $this->assertSame($this->approver->id, $fresh->approved_by);
        $this->assertSame(0, InAppNotification::where('type', 'approval_requested')->count());

        Sanctum::actingAs($this->creator, ['*']);
        $this->putJson("/api/v1/orders/{$order->id}", ['notes' => 'Back door', 'status' => 'shipped'])->assertOk();
        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
    }

    public function test_when_order_approval_is_off_edits_keep_the_recorded_approval(): void
    {
        $order = $this->approvedOrder();
        $this->requireOrderApproval(false);

        $this->webEdit($order, [], 20)->assertSessionMissing('error');

        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
    }

    public function test_a_shipped_order_is_not_sent_back_for_approval(): void
    {
        // Approval gates shipping; once the goods have left there is nothing
        // left to hold, and holding the order would block its delivery.
        $order = $this->approvedOrder();
        app(OrderService::class)->transitionStatus($order, OrderStatus::SHIPPED);
        Sanctum::actingAs($this->creator, ['*']);

        $this->putJson("/api/v1/orders/{$order->id}", ['notes' => 'x', 'customer_email' => 'buyer@example.test'])->assertOk();
        $this->putJson("/api/v1/orders/{$order->id}", ['status' => 'delivered'])->assertOk();

        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }

    public function test_an_order_with_an_open_shipment_is_not_sent_back_and_tracking_still_works(): void
    {
        // A shipment (label bought or not) already holds the order's lines:
        // holding the order for approval would make the next carrier update
        // fail when it moves the order to shipped.
        $order = $this->approvedOrder();
        $shipment = app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->creator);
        Sanctum::actingAs($this->creator, ['*']);

        $this->putJson("/api/v1/orders/{$order->id}", ['customer_address' => '9 New Dock Rd'])->assertOk();
        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);

        app(ShipmentService::class)->applyTrackingStatus($shipment->fresh(), ShipmentStatus::IN_TRANSIT, 'Scanned');

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
        $this->assertSame(ShipmentStatus::IN_TRANSIT, $shipment->fresh()->status);
        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
    }

    public function test_a_partially_shipped_order_is_not_sent_back_for_approval(): void
    {
        $order = $this->approvedOrder(4);
        $line = $order->items->first();
        $first = app(ShipmentService::class)->create($order, ['carrier' => 'manual', 'items' => [['order_item_id' => $line->id, 'quantity' => 2]]], $this->creator);
        app(ShipmentService::class)->markShipped($first, $this->creator);
        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
        $this->assertNull($order->fresh()->shipped_at);
        Sanctum::actingAs($this->creator, ['*']);

        $this->putJson("/api/v1/orders/{$order->id}", ['customer_name' => 'Someone Else'])->assertOk();

        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
    }

    public function test_approvers_are_notified_even_when_no_requester_is_known(): void
    {
        $order = $this->approvedOrder();
        // The creator has left and the change comes from a background job.
        DB::table('orders')->where('id', $order->id)->update(['created_by' => null]);
        auth()->forgetGuards();

        app(OrderService::class)->changeOrderDiscount($order->fresh(), 'fixed', 5);

        $this->assertBackToPending($order);
        $this->assertApproversNotified();
    }

    public function test_the_order_can_be_approved_again_after_a_change(): void
    {
        $order = $this->approvedOrder();
        Sanctum::actingAs($this->creator, ['*']);
        $this->putJson("/api/v1/orders/{$order->id}", ['customer_name' => 'New Buyer'])->assertOk();
        $this->assertBackToPending($order);

        app(OrderService::class)->approve($order->fresh(), $this->approver, 'Still fine');

        $this->assertSame(OrderApprovalStatus::APPROVED, $order->fresh()->approval_status);
    }
}
