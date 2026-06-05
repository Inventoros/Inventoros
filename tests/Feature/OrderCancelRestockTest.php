<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OrderCancelRestockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Stock changes fan out to low-stock/order notifications which apply
        // the org's (unset) email config and would build a real mailer; fake
        // mail+notifications so the cancel path under test is what's exercised.
        Mail::fake();
        Notification::fake();

        // Web routes redirect to /install until the system is marked installed.
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Cancel Org', 'email' => 'c@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'CAN-1', 'name' => 'Cancellable',
            'price' => 10.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@cancel.com', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    private function makeOrder(int $qty = 5): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => $qty, 'unit_price' => 10.00],
            ],
        ], $this->admin, 'manual');
    }

    public function test_service_cancel_restocks_and_is_idempotent(): void
    {
        // StockAdjustment::adjust() records auth()->id() as the actor, so the
        // restock ledger row needs an authenticated user present.
        $this->actingAs($this->admin);

        $order = $this->makeOrder(5);
        $this->assertSame(95, $this->product->fresh()->stock);

        app(OrderService::class)->cancel($order);
        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);

        // Idempotent: a second cancel must NOT restock again.
        app(OrderService::class)->cancel($order->fresh());
        $this->assertSame(100, $this->product->fresh()->stock);

        // Idempotent at the ledger level too: exactly one cancellation row.
        $this->assertSame(1, \App\Models\Inventory\StockAdjustment::query()
            ->where('product_id', $this->product->id)
            ->where('type', 'order_cancellation')
            ->count());
    }

    public function test_service_cancel_rejects_shipped(): void
    {
        $order = $this->makeOrder(5);
        $order->update(['status' => OrderStatus::SHIPPED]);

        $this->expectException(\RuntimeException::class);
        app(OrderService::class)->cancel($order->fresh());
    }

    public function test_graphql_cancel_restocks(): void
    {
        $order = $this->makeOrder(5);
        Sanctum::actingAs($this->admin, ['*']);

        $query = sprintf('mutation { updateOrder(id: %d, status: "cancelled") { id status } }', $order->id);
        $this->postJson('/graphql', ['query' => $query]);

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->product->id,
            'type' => 'order_cancellation',
        ]);
    }

    public function test_graphql_cannot_cancel_shipped(): void
    {
        $order = $this->makeOrder(5);
        $order->update(['status' => OrderStatus::SHIPPED]);
        Sanctum::actingAs($this->admin, ['*']);

        $query = sprintf('mutation { updateOrder(id: %d, status: "cancelled") { id } }', $order->id);
        $response = $this->postJson('/graphql', ['query' => $query]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(95, $this->product->fresh()->stock); // unchanged
    }

    public function test_web_cancel_restocks(): void
    {
        $order = $this->makeOrder(5);
        $this->assertSame(95, $this->product->fresh()->stock);
        $order->load('items');

        $response = $this->actingAs($this->admin)->put(route('orders.update', $order), [
            'customer_name' => $order->customer_name,
            'order_date' => now()->toDateString(),
            'status' => 'cancelled',
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
            ])->all(),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_web_cancel_rejects_shipped(): void
    {
        $order = $this->makeOrder(5);
        $order->update(['status' => OrderStatus::SHIPPED]);
        $order->load('items');

        $response = $this->actingAs($this->admin)->put(route('orders.update', $order), [
            'customer_name' => $order->customer_name,
            'order_date' => now()->toDateString(),
            'status' => 'cancelled',
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
            ])->all(),
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(95, $this->product->fresh()->stock); // not restocked
    }

    public function test_rest_cancel_restocks(): void
    {
        $order = $this->makeOrder(5);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->putJson("/api/v1/orders/{$order->id}", [
            'status' => 'cancelled',
        ]);

        $response->assertOk();
        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_rest_cannot_cancel_shipped(): void
    {
        $order = $this->makeOrder(5);
        $order->update(['status' => OrderStatus::SHIPPED]);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->putJson("/api/v1/orders/{$order->id}", [
            'status' => 'cancelled',
        ]);

        $response->assertStatus(422);
        $this->assertSame(95, $this->product->fresh()->stock); // unchanged
    }

    public function test_web_update_rejects_foreign_product_id(): void
    {
        $foreignOrg = \App\Models\Auth\Organization::create([
            'name' => 'Foreign', 'email' => 'f@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $foreignProduct = \App\Models\Inventory\Product::create([
            'organization_id' => $foreignOrg->id, 'sku' => 'FOR-1', 'name' => 'Foreign',
            'price' => 10.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $order = $this->makeOrder(5);

        $response = $this->actingAs($this->admin)->put(route('orders.update', $order), [
            'customer_name' => 'Acme',
            'order_date' => now()->toDateString(),
            'status' => 'pending',
            'items' => [
                ['product_id' => $foreignProduct->id, 'quantity' => 3, 'unit_price' => 10.00],
            ],
        ]);

        $response->assertSessionHasErrors('items.0.product_id');
        $this->assertSame(100, $foreignProduct->fresh()->stock); // untouched
    }
}
