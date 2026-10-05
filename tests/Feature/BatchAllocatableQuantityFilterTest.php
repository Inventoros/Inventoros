<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Auth\Organization;
use App\Models\Inventory\OrderItemBatchAllocation;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\User;
use App\Services\HookRegistry;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The batch_allocatable_quantity filter lets a plugin hold units of a batch
 * back from FEFO allocation (a quarantined or recalled lot, units on hold for
 * inspection) without touching the batch itself. Held units are skipped and
 * the next batch in FEFO order is used; the batch keeps its quantity.
 */
final class BatchAllocatableQuantityFilterTest extends TestCase
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
            'name' => 'Hold Org', 'email' => 'h@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@h.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->actingAs($this->creator);
    }

    private function batchProduct(int $stock): Product
    {
        return Product::create([
            'organization_id' => $this->org->id, 'sku' => 'HOLD-1', 'name' => 'Held',
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

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private function allocations(Order $order): array
    {
        return OrderItemBatchAllocation::where('order_item_id', $order->items()->first()->id)
            ->orderBy('id')->get()->map(fn ($a) => [$a->product_batch_id, (int) $a->quantity])->all();
    }

    public function test_the_filter_is_documented_in_the_hook_registry(): void
    {
        $this->assertArrayHasKey('batch_allocatable_quantity', HookRegistry::getFilters());
    }

    public function test_the_filter_receives_the_quantity_the_batch_the_product_and_the_order_line(): void
    {
        $product = $this->batchProduct(stock: 10);
        $batch = $this->batch($product, 'A', 10, '2026-01-01');
        $seen = [];

        add_filter('batch_allocatable_quantity', function ($quantity, $b, $p, $line) use (&$seen) {
            $seen[] = [$quantity, $b->id, $p->id, $line instanceof OrderItem];

            return $quantity;
        });

        $this->order($product, 3);

        $this->assertSame([[10, $batch->id, $product->id, true]], $seen);
        $this->assertSame(7, (int) $batch->fresh()->quantity);
    }

    public function test_a_held_batch_is_skipped_and_the_next_batch_in_fefo_order_is_used(): void
    {
        $product = $this->batchProduct(stock: 10);
        $held = $this->batch($product, 'HELD', 4, '2026-01-01');
        $next = $this->batch($product, 'NEXT', 6, '2026-06-01');

        add_filter('batch_allocatable_quantity', fn ($qty, $batch) => $batch->id === $held->id ? 0 : $qty);

        $order = $this->order($product, 5);

        $this->assertSame(4, (int) $held->fresh()->quantity);
        $this->assertSame(1, (int) $next->fresh()->quantity);
        $this->assertSame([[$next->id, 5]], $this->allocations($order));
    }

    public function test_part_of_a_batch_can_be_held(): void
    {
        $product = $this->batchProduct(stock: 10);
        $partly = $this->batch($product, 'PART', 4, '2026-01-01');
        $next = $this->batch($product, 'NEXT', 6, '2026-06-01');

        // Only 1 of PART's 4 units may be sold.
        add_filter('batch_allocatable_quantity', fn ($qty, $batch) => $batch->id === $partly->id ? 1 : $qty);

        $order = $this->order($product, 3);

        $this->assertSame(3, (int) $partly->fresh()->quantity);
        $this->assertSame(4, (int) $next->fresh()->quantity);
        $this->assertSame([[$partly->id, 1], [$next->id, 2]], $this->allocations($order));
    }

    public function test_answers_out_of_range_or_malformed_are_clamped_to_the_batch(): void
    {
        $product = $this->batchProduct(stock: 9);
        $neg = $this->batch($product, 'NEG', 3, '2026-01-01');
        $big = $this->batch($product, 'BIG', 3, '2026-02-01');
        $junk = $this->batch($product, 'JUNK', 3, '2026-03-01');

        add_filter('batch_allocatable_quantity', fn ($qty, $batch) => match ($batch->id) {
            $neg->id => -5,        // below zero: hold everything
            $big->id => 1000,      // above the batch: never more than it holds
            default => 'nonsense', // not a number: ignored, the batch is allocatable
        });

        $order = $this->order($product, 6);

        $this->assertSame(3, (int) $neg->fresh()->quantity);
        $this->assertSame(0, (int) $big->fresh()->quantity);
        $this->assertSame(0, (int) $junk->fresh()->quantity);
        $this->assertSame([[$big->id, 3], [$junk->id, 3]], $this->allocations($order));
    }

    public function test_held_units_do_not_count_toward_coverage_so_a_short_line_is_left_untouched(): void
    {
        $product = $this->batchProduct(stock: 10);
        $held = $this->batch($product, 'HELD', 8, '2026-01-01');
        $free = $this->batch($product, 'FREE', 2, '2026-06-01');

        add_filter('batch_allocatable_quantity', fn ($qty, $batch) => $batch->id === $held->id ? 0 : $qty);

        // Best-effort mode: 2 allocatable units cannot cover 5, so no batch is drawn.
        $order = $this->order($product, 5);

        $this->assertSame(8, (int) $held->fresh()->quantity);
        $this->assertSame(2, (int) $free->fresh()->quantity);
        $this->assertSame([], $this->allocations($order));
    }

    public function test_strict_mode_refuses_an_order_that_only_held_units_could_cover(): void
    {
        config(['inventory.strict_tracked_stock' => true]);
        $product = $this->batchProduct(stock: 10);
        $held = $this->batch($product, 'HELD', 8, '2026-01-01');
        $this->batch($product, 'FREE', 2, '2026-06-01');

        add_filter('batch_allocatable_quantity', fn ($qty, $batch) => $batch->id === $held->id ? 0 : $qty);

        try {
            $this->order($product, 5);
            $this->fail('The order should have been refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('2 available', $e->getMessage());
        }

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(8, (int) $held->fresh()->quantity);
        $this->assertSame(0, Order::count());
    }
}
