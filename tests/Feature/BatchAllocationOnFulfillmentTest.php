<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\OrderItemBatchAllocation;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Batch analogue of the serial allocation in #182: a batch-tracked product's
 * batches are consumed FEFO as orders are fulfilled and restored as they are
 * unwound. Best-effort during the transition — an order for an under-populated
 * product still succeeds, untouched.
 */
class BatchAllocationOnFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->org = Organization::create([
            'name' => 'Batch Org', 'email' => 'b@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@b.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->actingAs($this->creator);
    }

    private function batchProduct(int $stock): Product
    {
        return Product::create([
            'organization_id' => $this->org->id, 'sku' => 'BAT-1', 'name' => 'Batched',
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'tracking_type' => 'batch', 'is_active' => true,
        ]);
    }

    private function batch(Product $product, string $number, int $qty, ?string $expiry): ProductBatch
    {
        return ProductBatch::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'batch_number' => $number, 'quantity' => $qty, 'expiry_date' => $expiry,
        ]);
    }

    private function order(Product $product, int $qty): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 10.00]],
        ], $this->creator);
    }

    public function test_fulfilling_an_order_consumes_batches_first_expiry_first_out(): void
    {
        $product = $this->batchProduct(stock: 10);
        // Later expiry created first to prove ordering is by expiry, not by id.
        $late = $this->batch($product, 'LATE', 6, '2026-06-01');
        $early = $this->batch($product, 'EARLY', 4, '2026-01-01');

        $order = $this->order($product, 5);
        $orderItem = $order->items()->first();

        // The soonest-expiring batch is drained first: 4 from EARLY, then 1 from LATE.
        $this->assertSame(0, (int) $early->fresh()->quantity);
        $this->assertSame(5, (int) $late->fresh()->quantity);

        // Total stock decremented, and Σ batch quantity now equals it.
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(5, (int) ProductBatch::where('product_id', $product->id)->sum('quantity'));

        // Consumption is recorded per batch for exact restore.
        $this->assertEqualsCanonicalizing(
            [[$early->id, 4], [$late->id, 1]],
            OrderItemBatchAllocation::where('order_item_id', $orderItem->id)
                ->get()->map(fn ($a) => [$a->product_batch_id, $a->quantity])->all()
        );
    }

    public function test_undated_batches_are_consumed_after_dated_ones(): void
    {
        $product = $this->batchProduct(stock: 10);
        $undated = $this->batch($product, 'PLAIN', 5, null);
        $dated = $this->batch($product, 'DATED', 5, '2026-03-01');

        $this->order($product, 3);

        // Dated stock is used first; the undated batch is untouched.
        $this->assertSame(2, (int) $dated->fresh()->quantity);
        $this->assertSame(5, (int) $undated->fresh()->quantity);
    }

    public function test_cancelling_an_order_restores_every_batch_it_drew_from(): void
    {
        $product = $this->batchProduct(stock: 10);
        $early = $this->batch($product, 'EARLY', 4, '2026-01-01');
        $late = $this->batch($product, 'LATE', 6, '2026-06-01');

        $order = $this->order($product, 5);
        app(OrderService::class)->cancel($order);

        // Each batch gets back exactly what it gave, and the records are gone.
        $this->assertSame(4, (int) $early->fresh()->quantity);
        $this->assertSame(6, (int) $late->fresh()->quantity);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(0, OrderItemBatchAllocation::count());
    }

    public function test_order_succeeds_untouched_when_batches_are_short(): void
    {
        // Recorded stock 10 but batches only hold 3 (drift): an order for 5 must
        // still succeed with the batches left alone.
        $product = $this->batchProduct(stock: 10);
        $batch = $this->batch($product, 'ONLY', 3, '2026-01-01');

        $order = $this->order($product, 5);

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $batch->fresh()->quantity);
        $this->assertSame(0, OrderItemBatchAllocation::where('order_item_id', $order->items()->first()->id)->count());
    }
}
