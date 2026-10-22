<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockReservation;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The reservation service itself: reserve, release, consume, available,
 * idempotency, expiry, hooks, concurrency and tenant isolation.
 */
final class StockReservationServiceTest extends TestCase
{
    use RefreshDatabase, ReservationFixtures;

    /** @var array<string, array<int, array<int, mixed>>> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservationWorld();

        foreach (['stock_reserved', 'stock_reservation_released', 'stock_reservation_consumed', 'stock_changed', 'reservation_shortfall'] as $hook) {
            add_action($hook, function (...$args) use ($hook) {
                $this->fired[$hook][] = $args;
            });
        }
    }

    public function test_with_no_holds_available_equals_on_hand(): void
    {
        $this->assertSame(10, $this->reservations()->available($this->product));
        $this->assertSame(6, $this->reservations()->available($this->product, null, $this->binA->id));
        $this->assertSame(0, $this->reservations()->reserved($this->product));
    }

    public function test_reserve_holds_units_and_available_drops(): void
    {
        $hold = $this->hold(4);

        $this->assertTrue($hold->isActive());
        $this->assertSame('rental', $hold->owner_type);
        $this->assertSame('BOOKING-1', $hold->owner_reference);
        $this->assertSame($this->org->id, $hold->organization_id);
        $this->assertSame(4, $this->reservations()->reserved($this->product));
        $this->assertSame(6, $this->reservations()->available($this->product));
        // A product-level hold constrains the total, not a bin.
        $this->assertSame(6, $this->reservations()->available($this->product, null, $this->binA->id));

        // On hand is untouched.
        $this->assertSame(10, $this->stockOf());
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_over_reserving_is_refused(): void
    {
        $this->hold(7, 'BOOKING-1');

        $before = $this->snapshot();

        try {
            $this->hold(4, 'BOOKING-2');
            $this->fail('Reserving more than is available must be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('only 3 available', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_bin_hold_constrains_its_bin_and_the_total(): void
    {
        $this->hold(5, 'BIN-HOLD', $this->binA->id);

        $this->assertSame(1, $this->reservations()->available($this->product, null, $this->binA->id));
        $this->assertSame(4, $this->reservations()->available($this->product, null, $this->binB->id));
        $this->assertSame(5, $this->reservations()->available($this->product));

        // Bin A has only 1 free unit left.
        $this->expectException(InsufficientStockException::class);
        $this->hold(2, 'BIN-HOLD-2', $this->binA->id);
    }

    public function test_variant_holds_are_against_the_variant(): void
    {
        $variant = $this->makeVariantProduct('SHIRT', 8);
        $product = $variant->product;

        $this->reservations()->reserve($product, $variant, 5, 'shop', 'CART-1');

        $this->assertSame(3, $this->reservations()->available($product, $variant));
        $this->assertSame(3, $this->reservations()->available($product));

        // A product sold by variant must be reserved by variant.
        $this->expectException(InvalidArgumentException::class);
        $this->reservations()->reserve($product, null, 1, 'shop', 'CART-2');
    }

    public function test_the_same_owner_and_reference_reserve_once(): void
    {
        $first = $this->hold(3);
        $again = $this->hold(3);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, StockReservation::count());
        $this->assertSame(3, $this->reservations()->reserved($this->product));
        $this->assertCount(1, $this->fired['stock_reserved']);

        // A different quantity under the same reference is a conflict, not a second hold.
        try {
            $this->hold(5);
            $this->fail('A conflicting quantity must be refused.');
        } catch (InvalidStateException $e) {
            $this->assertSame('reservation_conflict', $e->errorCode);
        }

        $this->assertSame(3, $this->reservations()->reserved($this->product));
    }

    public function test_a_released_reference_can_be_reserved_again_but_a_consumed_one_cannot(): void
    {
        $hold = $this->hold(3);
        $this->assertSame(3, $this->reservations()->release('rental', 'BOOKING-1'));
        $this->assertSame(0, $this->reservations()->reserved($this->product));

        $again = $this->hold(2);
        $this->assertSame($hold->id, $again->id);
        $this->assertTrue($again->fresh()->isActive());
        $this->assertSame(2, $this->reservations()->reserved($this->product));

        $this->assertSame(2, $this->reservations()->consume('rental', 'BOOKING-1', $this->admin));

        $this->expectException(InvalidStateException::class);
        $this->hold(2);
    }

    public function test_release_frees_units_and_fires_the_hook_after_commit(): void
    {
        $this->hold(4);
        $this->fired = [];

        DB::transaction(function () {
            $this->assertSame(4, $this->reservations()->release('rental', 'BOOKING-1'));
            $this->assertArrayNotHasKey('stock_reservation_released', $this->fired);
        });

        $this->assertCount(1, $this->fired['stock_reservation_released']);
        $this->assertSame(StockReservation::RELEASED, $this->fired['stock_reservation_released'][0][0]->release_reason);
        $this->assertSame(10, $this->reservations()->available($this->product));

        // Releasing again releases nothing.
        $this->assertSame(0, $this->reservations()->release('rental', 'BOOKING-1'));
        $this->assertCount(1, $this->fired['stock_reservation_released']);
    }

    public function test_consumption_is_exactly_once(): void
    {
        $this->hold(4);
        $this->fired = [];

        $this->assertSame(4, $this->reservations()->consume('rental', 'BOOKING-1', $this->admin));
        $this->assertSame(0, $this->reservations()->consume('rental', 'BOOKING-1', $this->admin));

        $this->assertCount(1, $this->fired['stock_reservation_consumed']);
        [$reservation, $by] = $this->fired['stock_reservation_consumed'][0];
        $this->assertSame($this->admin->id, $by->id);
        $this->assertSame($this->admin->getMorphClass(), $reservation->consumed_by_type);
        $this->assertSame(0, $this->reservations()->reserved($this->product));
    }

    public function test_hooks_fire_after_commit_and_not_on_rollback(): void
    {
        try {
            DB::transaction(function () {
                $this->hold(4);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertArrayNotHasKey('stock_reserved', $this->fired);
        $this->assertArrayNotHasKey('stock_changed', $this->fired);
        $this->assertSame(0, StockReservation::count());

        $this->hold(4);
        $this->assertCount(1, $this->fired['stock_reserved']);
    }

    public function test_stock_changed_reports_reserved_before_and_after(): void
    {
        $this->hold(4);

        $this->assertCount(1, $this->fired['stock_changed']);
        [$product, $variant, $change] = $this->fired['stock_changed'][0];
        $this->assertSame($this->product->id, $product->id);
        $this->assertNull($variant);
        $this->assertSame(10, $change['before']);
        $this->assertSame(10, $change['after']);
        $this->assertSame(['before' => 0, 'after' => 4], $change['reserved']);

        $this->fired = [];
        $this->reservations()->release('rental', 'BOOKING-1');
        $this->assertSame(['before' => 4, 'after' => 0], $this->fired['stock_changed'][0][2]['reserved']);
    }

    public function test_stock_changed_has_no_reserved_key_when_holds_did_not_change(): void
    {
        StockAdjustment::adjust($this->product, -1, 'manual', actor: $this->admin);

        $this->assertArrayNotHasKey('reserved', $this->fired['stock_changed'][0][2]);
    }

    public function test_expired_holds_stop_counting_and_the_sweeper_releases_only_them(): void
    {
        $expiring = $this->reservations()->reserve($this->product, null, 3, 'rental', 'SOON', expiresAt: now()->addMinutes(5));
        $lasting = $this->reservations()->reserve($this->product, null, 2, 'rental', 'LATER', expiresAt: now()->addDay());
        $forever = $this->hold(1, 'OPEN');
        $consumed = $this->hold(1, 'USED');
        $this->reservations()->consume('rental', 'USED', $this->admin);

        $this->assertSame(6, $this->reservations()->reserved($this->product));

        $this->travel(10)->minutes();

        // Expired the moment it expired, before any sweep.
        $this->assertSame(3, $this->reservations()->reserved($this->product));
        $this->fired = [];

        Artisan::call('reservations:release-expired');

        $this->assertSame(StockReservation::EXPIRED, $expiring->fresh()->release_reason);
        $this->assertNotNull($expiring->fresh()->released_at);
        $this->assertNull($lasting->fresh()->released_at);
        $this->assertNull($forever->fresh()->released_at);
        $this->assertNull($consumed->fresh()->released_at);
        $this->assertCount(1, $this->fired['stock_reservation_released']);
        $this->assertSame(['before' => 6, 'after' => 3], $this->fired['stock_changed'][0][2]['reserved']);

        // Running it again changes nothing.
        $this->fired = [];
        Artisan::call('reservations:release-expired');
        $this->assertArrayNotHasKey('stock_reservation_released', $this->fired);
    }

    public function test_the_sweeper_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'reservations:release-expired'));

        $this->assertCount(1, $events);
    }

    public function test_an_expiry_in_the_past_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reservations()->reserve($this->product, null, 1, 'rental', 'PAST', expiresAt: now()->subMinute());
    }

    public function test_serial_holds_pin_one_available_serial(): void
    {
        $product = $this->makeProduct('CAMERA', 2, [], ['tracking_type' => 'serial']);
        $one = ProductSerial::create(['organization_id' => $this->org->id, 'product_id' => $product->id, 'serial_number' => 'CAM-1', 'status' => 'available']);
        $two = ProductSerial::create(['organization_id' => $this->org->id, 'product_id' => $product->id, 'serial_number' => 'CAM-2', 'status' => 'sold']);

        $hold = $this->reservations()->reserve($product, null, 1, 'rental', 'B-9', serial: $one);
        $this->assertSame($one->id, $hold->product_serial_id);
        $this->assertSame(1, $this->reservations()->available($product));
        $this->assertTrue($this->reservations()->isSerialHeld($one->id));

        // Already held by someone else.
        try {
            $this->reservations()->reserve($product, null, 1, 'other', 'X', serial: $one);
            $this->fail('A held serial cannot be held twice.');
        } catch (InsufficientStockException) {
        }

        // Not available.
        try {
            $this->reservations()->reserve($product, null, 1, 'rental', 'B-10', serial: $two);
            $this->fail('A sold serial cannot be held.');
        } catch (InsufficientStockException) {
        }

        // Quantity must be 1.
        $this->expectException(InvalidArgumentException::class);
        $this->reservations()->reserve($product, null, 2, 'rental', 'B-11', serial: $one);
    }

    public function test_batch_holds_are_limited_to_the_batch(): void
    {
        $product = $this->makeProduct('MILK', 10, [], ['tracking_type' => 'batch']);
        $batch = ProductBatch::create(['organization_id' => $this->org->id, 'product_id' => $product->id, 'batch_number' => 'LOT-1', 'quantity' => 4]);
        ProductBatch::create(['organization_id' => $this->org->id, 'product_id' => $product->id, 'batch_number' => 'LOT-2', 'quantity' => 6]);

        $this->reservations()->reserve($product, null, 3, 'kit', 'RUN-1', batch: $batch);
        $this->assertSame(7, $this->reservations()->available($product));

        $this->expectException(InsufficientStockException::class);
        $this->reservations()->reserve($product, null, 2, 'kit', 'RUN-2', batch: $batch);
    }

    public function test_reserve_reads_stock_under_the_row_lock(): void
    {
        // An order took 8 units after this caller read the product: its
        // in-memory copy still says 10 on hand.
        $stale = $this->product;
        DB::table('products')->where('id', $this->product->id)->update(['stock' => 2]);
        $this->assertSame(10, (int) $stale->stock);

        try {
            $this->reservations()->reserve($stale, null, 5, 'rental', 'RACE');
            $this->fail('reserve() must check the stock it locked, not the caller\'s copy.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(0, StockReservation::count());
    }

    public function test_two_reservations_racing_for_the_last_units_cannot_both_win(): void
    {
        // The second caller's lock is granted only after the first committed
        // its hold: it must see that hold when it sums what is reserved.
        $this->hold(6, 'FIRST');

        try {
            $this->hold(6, 'SECOND');
            $this->fail('The second hold must see the first.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(6, $this->reservations()->reserved($this->product));
    }

    public function test_reserve_locks_the_product_before_reading_holds(): void
    {
        $order = [];
        DB::listen(function (QueryExecuted $query) use (&$order) {
            $sql = strtolower(str_replace(['"', '`'], '', $query->sql));
            if (str_contains($sql, 'from products where')) {
                $order[] = 'product';
            } elseif (str_contains($sql, 'from stock_reservations')) {
                $order[] = 'holds';
            }
        });

        $this->hold(2);

        $this->assertSame('product', $order[0]);
    }

    public function test_reserve_and_release_are_tenant_isolated(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'o@other.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherUser = User::create([
            'name' => 'Oz', 'email' => 'oz@other.test', 'password' => bcrypt('x'),
            'organization_id' => $other->id, 'role' => 'admin',
        ]);
        $otherProduct = $this->makeProduct('DRILL-O', 10, [], [], $other);

        $mine = $this->hold(4, 'SAME-REF');
        $theirs = $this->hold(5, 'SAME-REF', product: $otherProduct);

        $this->assertSame($this->org->id, $mine->organization_id);
        $this->assertSame($other->id, $theirs->organization_id);
        $this->assertSame(6, $this->reservations()->available($this->product));
        $this->assertSame(5, $this->reservations()->available($otherProduct));

        // A signed-in user releases within their own organization only.
        $this->actingAs($otherUser);
        $this->assertSame(5, $this->reservations()->release('rental', 'SAME-REF'));
        $this->assertTrue($mine->fresh()->isActive());
        $this->assertSame(1, StockReservation::count(), 'The scope hides the other organization\'s holds.');

        // An explicit organization works outside a request too.
        $this->app['auth']->forgetGuards();
        $this->assertSame(4, $this->reservations()->release('rental', 'SAME-REF', $this->org->id));
    }

    public function test_reservation_rows_are_organization_scoped(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'o@other.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherProduct = $this->makeProduct('DRILL-O', 10, [], [], $other);
        $this->hold(4);
        $this->hold(5, product: $otherProduct);

        $this->actingAs($this->admin);

        $this->assertSame(1, StockReservation::count());
        $this->assertSame(4, (int) StockReservation::sum('quantity'));
    }

    public function test_owner_and_reference_are_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reservations()->reserve($this->product, null, 1, '', 'X');
    }
}
