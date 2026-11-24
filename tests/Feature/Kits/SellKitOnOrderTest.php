<?php

declare(strict_types=1);

namespace Tests\Feature\Kits;

use App\Exceptions\InsufficientStockException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\KitSaleService;
use App\Services\OrderService;
use App\Services\StockReservationService;
use App\Support\BackorderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Selling a kit on a new order takes its components out of stock, never the
 * kit's own stock, and records what each component gave in a snapshot row.
 */
final class SellKitOnOrderTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization();
        $this->admin = $this->makeAdmin($this->org);
        $this->actingAs($this->admin);
    }

    /**
     * @param  array<int, array{0: Product, 1: string|int}>  $parts
     * @param  array<string, mixed>  $attributes
     */
    private function makeKit(array $parts, array $attributes = []): Product
    {
        $kit = $this->makeProduct($this->org, array_merge(
            ['type' => 'kit', 'name' => 'Starter Kit', 'stock' => 0, 'price' => 30.00],
            $attributes,
        ));
        foreach ($parts as $i => [$component, $qty]) {
            ProductComponent::create([
                'parent_product_id' => $kit->id,
                'component_product_id' => $component->id,
                'quantity' => $qty,
                'sort_order' => $i,
            ]);
        }

        return $kit;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function createOrder(array $items, array $extra = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => $items,
        ], $extra), $this->admin);
    }

    public function test_selling_a_kit_takes_its_components_not_the_kit(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 10]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]]);

        $order = $this->createOrder([['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00]]);

        $rows = DB::table('order_items')->where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($kit->id, (int) $rows[0]->product_id);
        $this->assertSame(2, (int) $rows[0]->quantity);
        $this->assertSame('30.00', number_format((float) $rows[0]->unit_price, 2, '.', ''));

        $this->assertSame(6, (int) $a->fresh()->stock);
        $this->assertSame(8, (int) $b->fresh()->stock);
        $this->assertSame(0, (int) $kit->fresh()->stock);

        $ledger = DB::table('stock_adjustments')
            ->where('type', 'order_fulfillment')
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->orderBy('product_id')
            ->get();
        $this->assertSame([$a->id, $b->id], $ledger->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([-4, -2], $ledger->pluck('adjustment_quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertFalse(DB::table('stock_adjustments')->where('product_id', $kit->id)->exists());

        $snapshot = DB::table('order_item_kit_components')
            ->where('order_item_id', $rows[0]->id)
            ->orderBy('component_product_id')
            ->get();
        $this->assertCount(2, $snapshot);
        $this->assertSame([$a->id, $b->id], $snapshot->pluck('component_product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([2, 1], $snapshot->pluck('quantity_per_kit')->map(fn ($q) => (int) $q)->all());
        $this->assertSame([4, 2], $snapshot->pluck('quantity_taken')->map(fn ($q) => (int) $q)->all());
        $this->assertSame([$this->org->id, $this->org->id], $snapshot->pluck('organization_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_kit_line_and_a_plain_line_share_one_balance(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 5]);
        // The kit's own stock is never read; give it plenty to prove that.
        $kit = $this->makeKit([[$a, 2]], ['stock' => 10]);
        $ordersBefore = Order::withoutGlobalScopes()->count();

        try {
            // 2 kits need 4 of A and 2 plain A need 2: each fits alone, not together.
            $this->createOrder([
                ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
                ['product_id' => $a->id, 'quantity' => 2, 'unit_price' => 10.00],
            ]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(5, (int) $a->fresh()->stock);
        $this->assertSame($ordersBefore, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('order_item_kit_components')->count());
    }

    public function test_a_plain_line_before_a_kit_line_shares_one_balance(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 5]);
        // The kit's own stock is never read; give it plenty to prove that.
        $kit = $this->makeKit([[$a, 2]], ['stock' => 10]);

        try {
            $this->createOrder([
                ['product_id' => $a->id, 'quantity' => 2, 'unit_price' => 10.00],
                ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
            ]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(5, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $kit->fresh()->stock);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('stock_adjustments')->count());
    }

    public function test_a_kit_and_a_plain_line_that_fit_together_both_take_stock(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 6]);
        $kit = $this->makeKit([[$a, 2]]);

        $order = $this->createOrder([
            ['product_id' => $a->id, 'quantity' => 2, 'unit_price' => 10.00],
            ['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00],
        ]);

        $this->assertSame(0, (int) $a->fresh()->stock);
        $ledger = DB::table('stock_adjustments')
            ->where('product_id', $a->id)
            ->where('reference_id', $order->id)
            ->orderBy('id')
            ->get(['quantity_before', 'quantity_after']);
        // The ledger threads one running balance through both lines.
        $this->assertSame([[6, 4], [4, 0]], $ledger->map(fn ($r) => [(int) $r->quantity_before, (int) $r->quantity_after])->all());
    }

    public function test_a_short_kit_is_refused_even_with_backorders_on(): void
    {
        $settings = $this->org->settings ?? [];
        $settings[BackorderSettings::KEY] = ['enabled' => true, 'default_allow' => false];
        $this->org->update(['settings' => $settings]);

        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 3, 'allow_backorder' => true]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 10, 'allow_backorder' => true]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]], ['allow_backorder' => true]);

        try {
            $this->createOrder([['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00]]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Starter Kit', $e->getMessage());
            $this->assertStringContainsString('Part A', $e->getMessage());
        }

        $this->assertSame(3, (int) $a->fresh()->stock);
        $this->assertSame(10, (int) $b->fresh()->stock);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('stock_adjustments')->count());
    }

    public function test_a_component_held_by_another_reservation_limits_the_kit(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 5]);
        $kit = $this->makeKit([[$a, 2]], ['stock' => 10]);
        $reservations = app(StockReservationService::class);
        $reservations->reserve($a, null, 2, 'shop', 'CART-1');

        // 5 on hand less 2 held leaves 3: one kit, not two.
        try {
            $this->createOrder([['product_id' => $kit->id, 'quantity' => 2, 'unit_price' => 30.00]]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Part A', $e->getMessage());
        }
        $this->assertSame(5, (int) $a->fresh()->stock);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        $this->createOrder([['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00]]);

        $this->assertSame(3, (int) $a->fresh()->stock);
        $this->assertSame(2, $reservations->reservedTotal($a->id, null));
        $this->assertSame(1, DB::table('stock_reservations')->where('product_id', $a->id)->count());
        $this->assertSame(2, (int) DB::table('stock_reservations')->where('product_id', $a->id)->sum('quantity'));
    }

    public function test_take_moves_the_draws_it_is_given_and_never_rereads_the_bom(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10]);
        $c = $this->makeProduct($this->org, ['name' => 'Part C', 'stock' => 10]);
        $kit = $this->makeKit([[$a, 2]]);

        $order = $this->createOrder([['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00]]);
        $kitItem = $order->items()->firstOrFail();
        $this->assertSame(8, (int) $a->fresh()->stock);

        // The caller resolved and locked draws that differ from the BOM as it
        // stands now (A x2): take() must move exactly these, C x3.
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        DB::transaction(function () use ($order, $kitItem, $c): void {
            $lockedC = Product::whereKey($c->id)->lockForUpdate()->firstOrFail();
            app(KitSaleService::class)->take(
                $order, $kitItem, 2,
                [['target' => $lockedC, 'key' => 'p'.$c->id, 'per' => 3]],
                'Test take', $this->admin, $this->admin,
            );
        });

        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'product_components'))));
        $this->assertSame(8, (int) $a->fresh()->stock);
        $this->assertSame(4, (int) $c->fresh()->stock);
        $snapshot = DB::table('order_item_kit_components')
            ->where('order_item_id', $kitItem->id)
            ->where('component_product_id', $c->id)
            ->get();
        $this->assertCount(1, $snapshot);
        $this->assertSame(3, (int) $snapshot[0]->quantity_per_kit);
        $this->assertSame(6, (int) $snapshot[0]->quantity_taken);
        $this->assertTrue(DB::table('stock_adjustments')
            ->where('product_id', $c->id)->where('reference_id', $order->id)
            ->where('adjustment_quantity', -6)->exists());
    }

    public function test_a_kit_line_records_component_cost(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10, 'purchase_price' => 2.50]);
        $b = $this->makeProduct($this->org, ['name' => 'Part B', 'stock' => 10, 'purchase_price' => 1.00]);
        $kit = $this->makeKit([[$a, 2], [$b, 1]], ['purchase_price' => 99.00]);

        $order = $this->createOrder([['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00]]);

        $expected = app(KitSaleService::class)->costAtSale($kit);
        $this->assertSame('6.00', $expected);
        $this->assertSame($expected, number_format((float) $order->items()->first()->unit_cost, 2, '.', ''));
    }

    public function test_an_unsellable_kit_is_refused_over_rest(): void
    {
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 10]);
        $kit = $this->makeKit([[$a, '1.50']], ['name' => 'Half Kit', 'stock' => 10]);
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'Acme',
            'items' => [['product_id' => $kit->id, 'quantity' => 1, 'unit_price' => 30.00]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Half Kit', (string) $response->json('errors.items.0'));
        $this->assertSame(10, (int) $a->fresh()->stock);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_components_draw_from_the_orders_warehouse_first(): void
    {
        $east = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'East', 'code' => 'EAST', 'priority' => 10, 'is_active' => true]);
        $west = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'West', 'code' => 'WEST', 'priority' => 0, 'is_active' => true]);
        $eastShelf = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'E-1', 'code' => 'E-1', 'warehouse_id' => $east->id, 'is_active' => true,
        ]);
        $westShelf = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'W-1', 'code' => 'W-1', 'warehouse_id' => $west->id, 'is_active' => true,
        ]);

        // Without an order warehouse East (higher priority, primary) would drain first.
        $a = $this->makeProduct($this->org, ['name' => 'Part A', 'stock' => 20, 'location_id' => $eastShelf->id]);
        foreach ([[$eastShelf, 10], [$westShelf, 10]] as [$location, $qty]) {
            ProductLocationStock::create([
                'organization_id' => $this->org->id, 'product_id' => $a->id,
                'location_id' => $location->id, 'quantity' => $qty,
            ]);
        }
        $kit = $this->makeKit([[$a, 2]]);

        $this->createOrder(
            [['product_id' => $kit->id, 'quantity' => 3, 'unit_price' => 30.00]],
            ['warehouse_id' => $west->id],
        );

        $bin = fn (ProductLocation $loc) => (int) ProductLocationStock::where('product_id', $a->id)
            ->where('location_id', $loc->id)->value('quantity');
        $this->assertSame(10, $bin($eastShelf));
        $this->assertSame(4, $bin($westShelf));
        $this->assertSame(14, (int) $a->fresh()->stock);
        $this->assertSame(
            (int) $a->fresh()->stock,
            (int) ProductLocationStock::where('product_id', $a->id)->sum('quantity'),
        );
    }
}
