<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cancelling, rejecting, deleting or editing an order restocks through the
 * ledger, and every restock row needs an actor. Callers outside a request (a
 * queued job, a plugin's sync, a command) pass one explicitly; without one the
 * signed-in user is used, and with neither the order's creator, so a restock
 * never fails for want of a session.
 */
final class OrderRestockActorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $creator;

    private User $integration;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Actor Org', 'email' => 'a@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'ACT-1', 'name' => 'Actor product',
            'price' => 10, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@org.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->integration = User::create([
            'name' => 'Integration', 'email' => 'integration@org.test', 'password' => bcrypt('password'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function order(int $qty = 5, array $extra = []): Order
    {
        $order = app(OrderService::class)->create($extra + [
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty, 'unit_price' => 10]],
        ], $this->creator, 'manual');

        // Like a queue worker: nobody is signed in.
        auth()->forgetGuards();

        return $order;
    }

    private function restockActor(Order $order): ?int
    {
        return StockAdjustment::withoutGlobalScopes()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', 'order_cancellation')
            ->sole()
            ->user_id;
    }

    public function test_a_queued_job_can_cancel_an_order_as_an_explicit_actor(): void
    {
        $order = $this->order();

        CancelOrderForTest::dispatch($order->id, $this->integration->id);

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame($this->integration->id, $this->restockActor($order));
    }

    public function test_with_no_actor_and_nobody_signed_in_the_order_creator_is_recorded(): void
    {
        $order = $this->order();

        CancelOrderForTest::dispatch($order->id, null);

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame($this->creator->id, $this->restockActor($order));
    }

    public function test_the_signed_in_user_is_still_recorded_when_no_actor_is_passed(): void
    {
        $order = $this->order();
        $this->actingAs($this->integration);

        app(OrderService::class)->cancel($order);

        $this->assertSame($this->integration->id, $this->restockActor($order));
    }

    public function test_a_rejection_records_the_approver(): void
    {
        $order = $this->order(5, ['approval_status' => 'pending']);

        app(OrderService::class)->reject($order, $this->integration, 'Not today');

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame($this->integration->id, $this->restockActor($order));
    }

    public function test_restocking_for_deletion_accepts_an_actor(): void
    {
        $order = $this->order();

        app(OrderService::class)->restockForDeletion($order, $this->integration);

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame($this->integration->id, $this->restockActor($order));
    }

    public function test_replacing_lines_outside_a_request_records_the_actor_on_both_sides(): void
    {
        $order = $this->order(5);

        app(OrderService::class)->replaceItems($order, [
            ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10],
        ], $this->integration);

        $this->assertSame(98, $this->product->fresh()->stock);
        $this->assertSame($this->integration->id, $this->restockActor($order));

        // The new line's decrement (the last fulfilment row) too.
        $taken = StockAdjustment::withoutGlobalScopes()
            ->where('reference_type', Order::class)->where('reference_id', $order->id)
            ->where('type', 'order_fulfillment')
            ->orderByDesc('id')
            ->first();
        $this->assertSame(-2, $taken->adjustment_quantity);
        $this->assertSame($this->integration->id, $taken->user_id);
    }
}

/**
 * A queued job that cancels an order, the way an integration's sync would.
 */
final class CancelOrderForTest implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId, public ?int $actorId) {}

    public function handle(OrderService $orders): void
    {
        $actor = $this->actorId === null ? null : User::findOrFail($this->actorId);

        $orders->cancel(Order::withoutGlobalScopes()->findOrFail($this->orderId), $actor);
    }
}
