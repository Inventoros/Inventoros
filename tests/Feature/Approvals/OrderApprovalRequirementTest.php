<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStateException;
use App\Exceptions\ShippingException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Shipping\ShipmentService;
use App\Support\ApprovalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Sales order approval is an organization setting, off by default.
 *
 * Off: orders are created "not_required" and nothing waits on an approval.
 * On: orders are created "pending" and cannot be shipped or delivered (web,
 * REST, GraphQL, shipments) until someone approves them.
 */
final class OrderApprovalRequirementTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->product = $this->makeProduct($this->org, ['stock' => 50]);
    }

    private function requireOrderApproval(bool $on = true): void
    {
        $settings = $this->org->fresh()->settings ?? [];
        $settings[ApprovalSettings::KEY] = array_merge($settings[ApprovalSettings::KEY] ?? [], ['orders_enabled' => $on]);
        $this->org->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Buyer',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10]],
        ], $overrides);
    }

    private function createOrder(array $overrides = []): Order
    {
        $this->actingAs($this->admin);
        $order = app(OrderService::class)->create($this->orderPayload($overrides), $this->admin);
        auth()->forgetGuards();

        return $order->fresh();
    }

    // ---------------------------------------------------------------- off

    public function test_by_default_orders_do_not_need_approval_and_can_ship(): void
    {
        $this->assertFalse(ApprovalSettings::forOrganization($this->org)->ordersEnabled);

        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->orderPayload())
            ->assertRedirect(route('orders.index'));

        $order = Order::sole();
        $this->assertSame(OrderApprovalStatus::NOT_REQUIRED, $order->approval_status);
        $this->assertFalse($order->isPendingApproval());

        $shipment = app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);
        app(ShipmentService::class)->markShipped($shipment, $this->admin);

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_the_order_page_hides_the_approval_card_when_not_required(): void
    {
        $order = $this->createOrder();

        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.approval_status', 'not_required')
                ->where('approvalRequired', false));
    }

    // ----------------------------------------------------------------- on

    public function test_when_required_new_orders_wait_for_approval(): void
    {
        $this->requireOrderApproval();

        $order = $this->createOrder();

        $this->assertSame(OrderApprovalStatus::PENDING, $order->approval_status);
        $this->assertTrue($order->isPendingApproval());
    }

    public function test_a_pending_order_cannot_be_created_already_shipped(): void
    {
        $this->requireOrderApproval();

        $this->actingAs($this->admin)
            ->post(route('orders.store'), $this->orderPayload(['status' => 'shipped']))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Order::count());
    }

    public function test_a_pending_order_cannot_be_shipped_from_the_edit_form(): void
    {
        $this->requireOrderApproval();
        $order = $this->createOrder();

        $this->actingAs($this->admin)
            ->put(route('orders.update', $order), $this->orderPayload([
                'status' => 'shipped',
                'items' => [['id' => $order->items->first()->id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10]],
            ]))
            ->assertSessionHas('error');

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);

        // Moving it to processing is fine.
        $this->actingAs($this->admin)
            ->put(route('orders.update', $order), $this->orderPayload([
                'status' => 'processing',
                'items' => [['id' => $order->items->first()->id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10]],
            ]));
        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
    }

    public function test_a_pending_order_cannot_be_shipped_through_the_api(): void
    {
        $this->requireOrderApproval();
        $order = $this->createOrder();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/orders/{$order->id}", ['status' => 'delivered'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'approval_pending');

        $this->postJson("/api/v1/orders/{$order->id}/shipments", ['carrier' => 'manual'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(0, $order->shipments()->count());
    }

    public function test_a_pending_order_cannot_be_shipped_through_graphql(): void
    {
        $this->requireOrderApproval();
        $order = $this->createOrder();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/graphql', [
            'query' => 'mutation($id: Int!) { updateOrder(id: $id, status: "shipped") { id status } }',
            'variables' => ['id' => $order->id],
        ]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }

    public function test_shipment_service_and_status_transitions_refuse_a_pending_order(): void
    {
        $this->requireOrderApproval();
        $order = $this->createOrder();

        try {
            app(ShipmentService::class)->create($order, ['carrier' => 'manual'], $this->admin);
            $this->fail('A shipment was created for an order waiting for approval.');
        } catch (ShippingException $e) {
            $this->assertStringContainsString('approv', strtolower($e->getMessage()));
        }

        $this->expectException(InvalidStateException::class);
        app(OrderService::class)->transitionStatus($order, OrderStatus::SHIPPED);
    }

    public function test_once_approved_the_order_ships_normally(): void
    {
        $this->requireOrderApproval();
        $order = $this->createOrder();
        $approver = $this->makeAdmin($this->org);

        app(OrderService::class)->approve($order, $approver);

        $shipment = app(ShipmentService::class)->create($order->fresh(), ['carrier' => 'manual'], $approver);
        app(ShipmentService::class)->markShipped($shipment, $approver);

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_the_approvals_tab_saves_the_order_setting(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.approvals'), ['orders_enabled' => true])
            ->assertRedirect();

        $this->assertTrue(ApprovalSettings::forOrganization($this->org->fresh())->ordersEnabled);

        $this->actingAs($this->admin)
            ->get(route('settings.organization.index'))
            ->assertInertia(fn ($page) => $page->where('approvalSettings.orders_enabled', true));
    }

    // ---------------------------------------------------------- upgrade

    public function test_upgrade_marks_pending_orders_not_required_unless_the_org_requires_approval(): void
    {
        $strict = $this->makeOrganization('Strict');
        $settings = [ApprovalSettings::KEY => ['orders_enabled' => true]];
        $strict->forceFill(['settings' => $settings])->save();

        $make = fn (Organization $org, string $approval, string $number) => Order::create([
            'organization_id' => $org->id, 'order_number' => $number, 'customer_name' => 'X',
            'status' => 'pending', 'approval_status' => $approval, 'order_date' => now(),
            'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0,
        ]);
        $relaxedPending = $make($this->org, 'pending', 'A-1');
        $relaxedApproved = $make($this->org, 'approved', 'A-2');
        $relaxedRejected = $make($this->org, 'rejected', 'A-3');
        $strictPending = $make($strict, 'pending', 'S-1');

        $migration = require database_path('migrations/2026_09_28_103102_mark_orders_not_needing_approval.php');
        $migration->up();

        $this->assertSame(OrderApprovalStatus::NOT_REQUIRED, $relaxedPending->fresh()->approval_status);
        $this->assertSame(OrderApprovalStatus::APPROVED, $relaxedApproved->fresh()->approval_status);
        $this->assertSame(OrderApprovalStatus::REJECTED, $relaxedRejected->fresh()->approval_status);
        $this->assertSame(OrderApprovalStatus::PENDING, $strictPending->fresh()->approval_status);
    }
}
