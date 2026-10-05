<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReturnOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Receiving a return restocks through the ledger, and every ledger row needs
 * an actor. A queued job, command or plugin sync (a marketplace return
 * received by an integration) has nobody signed in, so receive() takes an
 * optional actor; without one it records the signed-in user, then whoever
 * approved the return, then the order's creator.
 */
final class ReturnReceiveActorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $creator;

    private User $approver;

    private User $integration;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Return Actor Org', 'email' => 'ra@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true,
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'RA-1', 'name' => 'Returnable',
            'price' => 10, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0,
            'location_id' => $location->id, 'is_active' => true,
        ]);
        foreach (['creator' => 'Creator', 'approver' => 'Approver', 'integration' => 'Integration'] as $key => $name) {
            $this->{$key} = User::create([
                'name' => $name, 'email' => "{$key}@ra.test", 'password' => bcrypt('password'),
                'organization_id' => $this->org->id, 'role' => 'admin',
            ]);
        }
    }

    /**
     * An approved return of $qty units of a delivered order, with nobody
     * signed in afterwards (like a queue worker).
     */
    private function approvedReturn(int $qty = 4, ?ProductVariant $variant = null): ReturnOrder
    {
        $line = ['product_id' => $this->product->id, 'quantity' => $qty, 'unit_price' => 10];
        if ($variant !== null) {
            $line['product_variant_id'] = $variant->id;
        }

        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'delivered', 'order_date' => now()->toDateString(),
            'items' => [$line],
        ], $this->creator, 'manual');

        $service = app(ReturnOrderService::class);
        $return = $service->create($this->org->id, $this->creator, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'Damaged',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => $qty, 'condition' => 'new', 'restock' => true]],
        ]);
        $service->approve($return, $this->approver);

        auth()->forgetGuards();
        $this->assertFalse(auth()->check());

        return ReturnOrder::withoutGlobalScopes()->findOrFail($return->id);
    }

    private function ledgerActor(ReturnOrder $return): ?int
    {
        return StockAdjustment::withoutGlobalScopes()
            ->where('reference_type', ReturnOrder::class)
            ->where('reference_id', $return->id)
            ->where('type', 'return')
            ->sole()
            ->user_id;
    }

    public function test_a_queued_job_can_receive_a_return_as_an_explicit_actor(): void
    {
        $return = $this->approvedReturn(4);

        ReceiveReturnForTest::dispatch($return->id, $this->integration->id);

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame('received', $return->fresh()->status);
        $this->assertSame($this->integration->id, $this->ledgerActor($return));
        $this->assertSame($this->integration->id, (int) $return->fresh()->processed_by);
    }

    public function test_with_no_actor_and_nobody_signed_in_the_approver_is_recorded(): void
    {
        $return = $this->approvedReturn(4);

        ReceiveReturnForTest::dispatch($return->id, null);

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame('received', $return->fresh()->status);
        $this->assertSame($this->approver->id, $this->ledgerActor($return));
    }

    public function test_the_signed_in_user_is_recorded_when_no_actor_is_passed(): void
    {
        $return = $this->approvedReturn(4);
        $this->actingAs($this->integration);

        app(ReturnOrderService::class)->receive($return);

        $this->assertSame($this->integration->id, $this->ledgerActor($return));
    }

    public function test_a_variant_return_received_without_a_session_records_the_actor(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id, 'organization_id' => $this->org->id,
            'sku' => 'RA-1-S', 'title' => 'S', 'option_values' => ['Size' => 'S'],
            'price' => 10, 'stock' => 10, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);
        $this->product->update(['has_variants' => true]);

        $return = $this->approvedReturn(3, $variant);
        $this->assertSame(7, (int) $variant->fresh()->stock);

        ReceiveReturnForTest::dispatch($return->id, $this->integration->id);

        $this->assertSame(10, (int) $variant->fresh()->stock);
        $this->assertSame($this->integration->id, $this->ledgerActor($return));
    }
}

/**
 * A queued job that receives a return, the way an integration's sync would.
 */
final class ReceiveReturnForTest implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $returnId, public ?int $actorId) {}

    public function handle(ReturnOrderService $returns): void
    {
        $actor = $this->actorId === null ? null : User::findOrFail($this->actorId);

        $returns->receive(ReturnOrder::withoutGlobalScopes()->findOrFail($this->returnId), $actor);
    }
}
