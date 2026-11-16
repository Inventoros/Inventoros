<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderItemException;
use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An order's status only moves forward: cancelled and delivered are terminal,
 * and a shipped order can only be delivered. Every surface (web, REST,
 * GraphQL, the service) shares the rule, so an order whose stock was already
 * given back cannot be revived, and goods that left cannot be restocked by
 * moving the order back first.
 */
final class OrderStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Transition Org', 'email' => 't@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'TRN-1', 'name' => 'Transition Widget',
            'price' => 10.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@transition.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    private function makeOrder(string $status = 'pending', ?string $approval = null): Order
    {
        return app(OrderService::class)->create(array_filter([
            'customer_name' => 'Acme',
            'status' => $status,
            'approval_status' => $approval,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10.00],
            ],
        ], fn ($v) => $v !== null), $this->admin, 'manual')->load('items');
    }

    private function graphqlStatus(Order $order, string $status): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin, ['*']);

        return $this->postJson('/graphql', [
            'query' => sprintf('mutation { updateOrder(id: %d, status: "%s") { id status } }', $order->id, $status),
        ]);
    }

    private function webUpdate(Order $order, string $status, ?int $quantity = null): \Illuminate\Testing\TestResponse
    {
        $order = $order->fresh('items');

        return $this->actingAs($this->admin)->put(route('orders.update', $order), [
            'customer_name' => $order->customer_name,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'quantity' => $quantity ?? $i->quantity,
                'unit_price' => $i->unit_price,
            ])->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Cancelled and rejected orders stay cancelled.
    // ------------------------------------------------------------------

    public function test_graphql_cannot_reactivate_a_cancelled_order(): void
    {
        $this->actingAs($this->admin);
        $order = $this->makeOrder();
        app(OrderService::class)->cancel($order);
        $this->assertSame(100, $this->product->fresh()->stock);

        foreach (['pending', 'processing', 'shipped', 'delivered'] as $status) {
            $response = $this->graphqlStatus($order, $status);

            $this->assertNotEmpty($response->json('errors'), "Reactivating to {$status} should be refused.");
            $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        }

        $this->assertSame(100, $this->product->fresh()->stock);
    }

    public function test_graphql_cannot_reactivate_a_rejected_order(): void
    {
        $order = $this->makeOrder('pending', 'pending');
        $approver = User::create([
            'name' => 'Approver', 'email' => 'approver@transition.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->actingAs($approver);
        app(OrderService::class)->reject($order, $approver, 'Not this one');
        $this->assertSame(100, $this->product->fresh()->stock);

        $response = $this->graphqlStatus($order, 'processing');

        $this->assertNotEmpty($response->json('errors'));
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $fresh->status);
        $this->assertTrue($fresh->isRejected());
        $this->assertSame(100, $this->product->fresh()->stock);
    }

    // ------------------------------------------------------------------
    // Shipped orders only move to delivered; delivered is terminal.
    // ------------------------------------------------------------------

    public function test_graphql_cannot_move_a_shipped_order_back(): void
    {
        $order = $this->makeOrder('shipped');

        $response = $this->graphqlStatus($order, 'pending');

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_web_cannot_move_a_delivered_order_back(): void
    {
        $order = $this->makeOrder('delivered');

        $this->webUpdate($order, 'pending')->assertSessionHas('error');

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }

    public function test_web_cannot_move_a_shipped_order_back(): void
    {
        $order = $this->makeOrder('shipped');

        $this->webUpdate($order, 'processing')->assertSessionHas('error');

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_api_cannot_move_shipped_or_delivered_orders_back(): void
    {
        $shipped = $this->makeOrder('shipped');
        $delivered = $this->makeOrder('delivered');
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/v1/orders/{$shipped->id}", ['status' => 'processing'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_state_transition');
        $this->putJson("/api/v1/orders/{$delivered->id}", ['status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_state_transition');
        $this->putJson("/api/v1/orders/{$delivered->id}", ['status' => 'shipped'])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::SHIPPED, $shipped->fresh()->status);
        $this->assertSame(OrderStatus::DELIVERED, $delivered->fresh()->status);
    }

    public function test_the_service_refuses_moving_a_delivered_order_back(): void
    {
        $order = $this->makeOrder('delivered');

        $this->expectException(InvalidStateException::class);

        try {
            app(OrderService::class)->transitionStatus($order, OrderStatus::PENDING);
        } finally {
            $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        }
    }

    public function test_the_model_refuses_moving_a_shipped_order_back_from_any_caller(): void
    {
        $order = $this->makeOrder('shipped');

        $this->expectException(InvalidStateException::class);

        try {
            $order->update(['status' => OrderStatus::PENDING]);
        } finally {
            $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
        }
    }

    // ------------------------------------------------------------------
    // Goods that left cannot be restocked, whatever the status says.
    // ------------------------------------------------------------------

    /**
     * Rows reverted to pending before the transition rule existed keep the
     * shipped_at stamp.
     */
    private function legacyRevertedOrder(): Order
    {
        $order = $this->makeOrder('shipped');
        DB::table('orders')->where('id', $order->id)->update(['status' => 'pending', 'shipped_at' => now()]);

        return $order->fresh('items');
    }

    public function test_an_order_stamped_shipped_cannot_be_cancelled_into_a_restock(): void
    {
        $this->actingAs($this->admin);
        $order = $this->legacyRevertedOrder();
        $this->assertSame(95, $this->product->fresh()->stock);

        try {
            app(OrderService::class)->cancel($order);
            $this->fail('Cancelling an order whose goods shipped should be refused.');
        } catch (InvalidStateException) {
        }

        $this->assertSame(95, $this->product->fresh()->stock);
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }

    public function test_deleting_an_order_stamped_shipped_does_not_restock(): void
    {
        $order = $this->legacyRevertedOrder();

        $this->actingAs($this->admin)
            ->delete(route('orders.destroy', $order))
            ->assertRedirect(route('orders.index'));

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertSame(95, $this->product->fresh()->stock);
    }

    public function test_line_edits_are_refused_once_an_order_has_shipped(): void
    {
        $this->actingAs($this->admin);

        foreach ([$this->makeOrder('shipped'), $this->makeOrder('delivered'), $this->legacyRevertedOrder()] as $order) {
            $stockBefore = (int) $this->product->fresh()->stock;

            try {
                DB::transaction(fn () => app(OrderService::class)->replaceItems($order->fresh(), [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10.00],
                ]));
                $this->fail('Replacing the lines of a shipped order should be refused.');
            } catch (InvalidOrderItemException) {
            }

            $this->assertSame($stockBefore, (int) $this->product->fresh()->stock);
            $this->assertSame(5, (int) $order->fresh('items')->items->first()->quantity);
        }
    }

    public function test_web_line_edit_on_a_shipped_order_is_refused(): void
    {
        $order = $this->makeOrder('shipped');
        $stockBefore = (int) $this->product->fresh()->stock;

        $this->webUpdate($order, 'shipped', 1)->assertSessionHas('error');

        $this->assertSame($stockBefore, (int) $this->product->fresh()->stock);
        $this->assertSame(5, (int) $order->fresh('items')->items->first()->quantity);
    }

    // ------------------------------------------------------------------
    // The legitimate forward path still works on every surface.
    // ------------------------------------------------------------------

    public function test_api_moves_an_order_forward_and_stamps_the_dates(): void
    {
        $order = $this->makeOrder();
        Sanctum::actingAs($this->admin, ['*']);

        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $this->putJson("/api/v1/orders/{$order->id}", ['status' => $status])->assertOk();
            $this->assertSame($status, $order->fresh()->status->value);
        }

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->shipped_at);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_web_moves_an_order_forward(): void
    {
        $order = $this->makeOrder();

        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $this->webUpdate($order, $status)->assertSessionHasNoErrors()->assertSessionMissing('error');
            $this->assertSame($status, $order->fresh()->status->value);
        }
    }

    public function test_graphql_moves_an_order_forward(): void
    {
        $order = $this->makeOrder('processing');

        foreach (['shipped', 'delivered'] as $status) {
            $response = $this->graphqlStatus($order, $status);
            $this->assertEmpty($response->json('errors'));
            $this->assertSame($status, $order->fresh()->status->value);
        }
    }

    public function test_an_open_order_can_still_move_between_pending_and_processing_and_be_cancelled(): void
    {
        $this->actingAs($this->admin);
        $order = $this->makeOrder('processing');

        app(OrderService::class)->transitionStatus($order, OrderStatus::PENDING);
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);

        app(OrderService::class)->cancel($order->fresh());
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(100, $this->product->fresh()->stock);
    }

    public function test_a_status_unchanged_edit_on_a_delivered_order_is_allowed(): void
    {
        $order = $this->makeOrder('delivered');
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/v1/orders/{$order->id}", ['status' => 'delivered', 'notes' => 'Left at the door'])
            ->assertOk();

        $this->assertSame('Left at the door', $order->fresh()->notes);
    }
}
