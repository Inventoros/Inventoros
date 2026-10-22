<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Imports\OrdersImport;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreateOrderTool;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\StockReservation;
use App\Models\Order\Order;
use App\Services\OrderService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Concerns\AssertsStockInvariants;
use Tests\TestCase;

/**
 * Order creation (web, REST, GraphQL, MCP, import) and order edits check
 * requests against available stock (on hand less active holds), and an order
 * carrying its reservation consumes it exactly once.
 *
 * The product has 10 on hand; a hold of 7 leaves 3 available.
 */
final class OrderPathsHonourReservationsTest extends TestCase
{
    use AssertsStockInvariants, RefreshDatabase, ReservationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservationWorld();
        $this->hold(7);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function orderData(int $quantity, array $extra = []): array
    {
        return $extra + [
            'customer_name' => 'Walk-in', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => '10.00']],
        ];
    }

    private function service(): OrderService
    {
        return app(OrderService::class);
    }

    // ---------------------------------------------------------------
    // Order create: the service every surface uses
    // ---------------------------------------------------------------

    public function test_service_refuses_more_than_available_and_changes_nothing(): void
    {
        $before = $this->snapshot();

        try {
            $this->service()->create($this->orderData(4), $this->admin);
            $this->fail('An order above available must be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Available: 3', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Order::count());
    }

    public function test_service_accepts_an_order_within_available(): void
    {
        $order = $this->service()->create($this->orderData(3), $this->admin);

        $this->assertSame(7, $this->stockOf());
        $this->assertSame(7, $this->reservations()->reserved($this->product));
        $this->assertSame(0, $this->reservations()->available($this->product));
        $this->assertSame(1, $order->items()->count());
        $this->assertBinnedStockBalanced();
        $this->assertLedgerExplainsStock($this->product, 10);
    }

    public function test_lines_of_the_same_product_share_one_available_balance(): void
    {
        $data = $this->orderData(2);
        $data['items'][] = ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '10.00'];
        $before = $this->snapshot();

        $this->expectException(InsufficientStockException::class);

        try {
            $this->service()->create($data, $this->admin);
        } finally {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_a_hold_placed_before_the_lock_is_granted_is_seen(): void
    {
        $this->reservations()->release('rental', 'BOOKING-1');

        // A reservation commits while the order waits for the product lock:
        // the order must sum holds after it holds the lock.
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected) {
            if (! $injected && preg_match('/from products where/i', str_replace(['"', '`'], '', $query->sql))) {
                $injected = true;
                DB::table('stock_reservations')->insert([
                    'organization_id' => $this->org->id, 'product_id' => $this->product->id,
                    'quantity' => 8, 'owner_type' => 'rental', 'owner_reference' => 'LATE',
                    'scope_key' => StockReservation::scopeKeyFor($this->product->id, null, null, null, null),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        try {
            $this->service()->create($this->orderData(3), $this->admin);
            $this->fail('The order must see the hold committed before its lock.');
        } catch (InsufficientStockException) {
        }

        $this->assertTrue($injected);
        $this->assertSame(10, $this->stockOf());
    }

    public function test_a_product_level_hold_lets_the_order_draw_any_bin(): void
    {
        // Bin A holds 6, bin B 4: 3 available may come from either.
        $this->service()->create($this->orderData(3), $this->admin);

        $this->assertSame(7, $this->binQuantity($this->binA->id) + $this->binQuantity($this->binB->id));
        $this->assertBinnedStockBalanced();
    }

    public function test_a_bin_hold_keeps_the_order_out_of_the_held_units(): void
    {
        $this->reservations()->release('rental', 'BOOKING-1');
        // All 6 units of bin A are held; bin B's 4 are free.
        $this->hold(6, 'BIN', $this->binA->id);

        $this->service()->create($this->orderData(4), $this->admin);

        $this->assertSame(6, $this->binQuantity($this->binA->id));
        $this->assertSame(0, $this->binQuantity($this->binB->id));
        $this->assertBinnedStockBalanced();

        $this->expectException(InsufficientStockException::class);
        $this->service()->create($this->orderData(1), $this->admin);
    }

    public function test_variant_holds_are_honoured(): void
    {
        $variant = $this->makeVariantProduct('SHIRT', 8);
        $this->reservations()->reserve($variant->product, $variant, 6, 'shop', 'CART');
        $line = fn (int $qty) => [
            'customer_name' => 'V', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $variant->product_id, 'product_variant_id' => $variant->id, 'quantity' => $qty, 'unit_price' => '20.00']],
        ];

        try {
            $this->service()->create($line(3), $this->admin);
            $this->fail('A variant order above available must be refused.');
        } catch (InsufficientStockException) {
        }
        $this->assertSame(8, (int) $variant->fresh()->stock);

        $this->service()->create($line(2), $this->admin);
        $this->assertSame(6, (int) $variant->fresh()->stock);
    }

    public function test_historical_orders_that_take_no_stock_are_not_checked(): void
    {
        $order = $this->service()->create($this->orderData(9), $this->admin, adjustStock: false);

        $this->assertNotNull($order->id);
        $this->assertSame(10, $this->stockOf());
    }

    // ---------------------------------------------------------------
    // Surfaces
    // ---------------------------------------------------------------

    public function test_web_order_create_honours_holds(): void
    {
        $before = $this->snapshot();

        $this->actingAs($this->admin)->post(route('orders.store'), $this->orderData(4));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Order::count());

        $this->actingAs($this->admin)->post(route('orders.store'), $this->orderData(3))->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
        $this->assertSame(7, $this->stockOf());
    }

    public function test_rest_order_create_honours_holds(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $before = $this->snapshot();

        $this->postJson('/api/v1/orders', $this->orderData(4))->assertStatus(422);
        $this->assertSame($before, $this->snapshot());

        $this->postJson('/api/v1/orders', $this->orderData(3))->assertStatus(201);
        $this->assertSame(7, $this->stockOf());
    }

    public function test_graphql_order_create_honours_holds(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $mutation = fn (int $qty) => sprintf(
            'mutation { createOrder(customer_name: "Gql", items: [{product_id: %d, quantity: %d, unit_price: 10}]) { id } }',
            $this->product->id, $qty,
        );
        $before = $this->snapshot();

        $this->assertNotEmpty($this->postJson('/graphql', ['query' => $mutation(4)])->json('errors'));
        $this->assertSame($before, $this->snapshot());

        $response = $this->postJson('/graphql', ['query' => $mutation(3)]);
        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame(7, $this->stockOf());
    }

    public function test_mcp_order_create_honours_holds(): void
    {
        $before = $this->snapshot();

        InventorosServer::actingAs($this->admin)
            ->tool(CreateOrderTool::class, ['customer_name' => 'Mcp', 'items' => [['product_id' => $this->product->id, 'quantity' => 4]]])
            ->assertHasErrors();
        $this->assertSame($before, $this->snapshot());

        $this->app['auth']->forgetGuards();
        InventorosServer::actingAs($this->admin)
            ->tool(CreateOrderTool::class, ['customer_name' => 'Mcp', 'items' => [['product_id' => $this->product->id, 'quantity' => 3]]])
            ->assertOk();
        $this->assertSame(7, $this->stockOf());
    }

    public function test_order_import_honours_holds(): void
    {
        $header = 'external_reference,order_date,status,customer_name,customer_email,product_sku,variant_sku,quantity,unit_price,order_tax,order_shipping,notes';
        $import = function (int $qty, string $ref) use ($header) {
            $import = new OrdersImport($this->admin, false);
            Excel::import($import, UploadedFile::fake()->createWithContent('orders.csv', "{$header}\n{$ref},2026-01-05,pending,Acme,,DRILL,,{$qty},10,,,\n"));

            return $import->getStats();
        };
        $before = $this->snapshot();

        $refused = $import(4, 'SHOP-1');
        $this->assertSame(0, $refused['imported']);
        $this->assertNotEmpty($refused['errors']);
        $this->assertSame($before, $this->snapshot());

        $this->assertSame(1, $import(3, 'SHOP-2')['imported']);
        $this->assertSame(7, $this->stockOf());
    }

    // ---------------------------------------------------------------
    // Order edit
    // ---------------------------------------------------------------

    public function test_order_edit_honours_holds(): void
    {
        $order = $this->service()->create($this->orderData(2), $this->admin);
        $before = $this->snapshot();

        // 2 already on the order + 1 still available = at most 3.
        try {
            DB::transaction(fn () => $this->service()->replaceItems($order->fresh(), [
                ['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '10.00'],
            ], $this->admin));
            $this->fail('An edit above available must be refused.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, (int) $order->fresh()->items()->sole()->quantity);

        DB::transaction(fn () => $this->service()->replaceItems($order->fresh(), [
            ['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '10.00'],
        ], $this->admin));

        $this->assertSame(7, $this->stockOf());
        $this->assertBinnedStockBalanced();
    }

    public function test_web_order_edit_honours_holds(): void
    {
        $order = $this->service()->create($this->orderData(2), $this->admin);
        $before = $this->snapshot();

        $this->actingAs($this->admin)->put(route('orders.update', $order), $this->orderData(4, [
            'customer_name' => 'Walk-in',
        ]))->assertSessionHas('error');

        $this->assertSame($before, $this->snapshot());
    }

    // ---------------------------------------------------------------
    // An order consuming its reservation
    // ---------------------------------------------------------------

    public function test_an_order_carrying_its_reservation_checks_available_plus_that_hold(): void
    {
        $data = $this->orderData(10, ['reservation_owner' => 'rental', 'reservation_reference' => 'BOOKING-1']);

        $consumed = [];
        add_action('stock_reservation_consumed', function ($reservation, $by) use (&$consumed) {
            $consumed[] = [$reservation->id, $by->id];
        });

        $order = $this->service()->create($data, $this->admin);

        $this->assertSame(0, $this->stockOf());
        $this->assertSame(0, $this->reservations()->reserved($this->product));
        $hold = StockReservation::sole();
        $this->assertNotNull($hold->consumed_at);
        $this->assertSame($order->getMorphClass(), $hold->consumed_by_type);
        $this->assertSame($order->id, (int) $hold->consumed_by_id);
        $this->assertSame([[$hold->id, $order->id]], $consumed);
        $this->assertBinnedStockBalanced();
    }

    public function test_the_hold_is_consumed_once(): void
    {
        $this->service()->create($this->orderData(2, ['reservation_owner' => 'rental', 'reservation_reference' => 'BOOKING-1']), $this->admin);
        $before = $this->snapshot();

        try {
            $this->service()->create($this->orderData(1, ['reservation_owner' => 'rental', 'reservation_reference' => 'BOOKING-1']), $this->admin);
            $this->fail('A consumed reservation cannot be consumed again.');
        } catch (InvalidStateException $e) {
            $this->assertSame('reservation_not_active', $e->errorCode);
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_an_order_above_available_plus_its_hold_leaves_the_hold_active(): void
    {
        $this->hold(1, 'OTHER');
        $before = $this->snapshot();

        try {
            // 2 free + 7 held for this order = 9.
            $this->service()->create($this->orderData(10, ['reservation_owner' => 'rental', 'reservation_reference' => 'BOOKING-1']), $this->admin);
            $this->fail('The order may use only available stock plus its own hold.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertTrue(StockReservation::where('owner_reference', 'BOOKING-1')->sole()->isActive());
    }

    public function test_another_organizations_reservation_cannot_be_consumed(): void
    {
        $other = \App\Models\Auth\Organization::create(['name' => 'Other', 'email' => 'x@other.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherProduct = $this->makeProduct('OTHER', 5, [], [], $other);
        $this->hold(5, 'THEIRS', product: $otherProduct);

        $this->expectException(InvalidStateException::class);
        $this->service()->create($this->orderData(1, ['reservation_owner' => 'rental', 'reservation_reference' => 'THEIRS']), $this->admin);
    }

    // ---------------------------------------------------------------
    // Tracked units
    // ---------------------------------------------------------------

    public function test_held_serials_are_not_allocated_to_other_orders(): void
    {
        $this->reservations()->release('rental', 'BOOKING-1');
        $camera = $this->makeProduct('CAMERA', 3, [], ['tracking_type' => 'serial']);
        $serials = collect(['CAM-1', 'CAM-2', 'CAM-3'])->map(fn ($sn) => ProductSerial::create([
            'organization_id' => $this->org->id, 'product_id' => $camera->id, 'serial_number' => $sn, 'status' => 'available',
        ]));
        $this->reservations()->reserve($camera, null, 1, 'rental', 'B-CAM', serial: $serials[0]);
        $line = fn (int $qty, array $extra = []) => $extra + [
            'customer_name' => 'C', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $camera->id, 'quantity' => $qty, 'unit_price' => '10.00']],
        ];

        $this->service()->create($line(2), $this->admin);

        $this->assertSame('available', $serials[0]->fresh()->status, 'The held serial stays put.');
        $this->assertSame('sold', $serials[1]->fresh()->status);
        $this->assertSame('sold', $serials[2]->fresh()->status);

        // Its consumer gets exactly that serial.
        $this->service()->create($line(1, ['reservation_owner' => 'rental', 'reservation_reference' => 'B-CAM']), $this->admin);
        $this->assertSame('sold', $serials[0]->fresh()->status);
    }

    public function test_held_batch_units_are_not_allocated_to_other_orders(): void
    {
        $this->reservations()->release('rental', 'BOOKING-1');
        $milk = $this->makeProduct('MILK', 10, [], ['tracking_type' => 'batch']);
        $early = ProductBatch::create(['organization_id' => $this->org->id, 'product_id' => $milk->id, 'batch_number' => 'EARLY', 'quantity' => 4, 'expiry_date' => now()->addDays(2)]);
        $late = ProductBatch::create(['organization_id' => $this->org->id, 'product_id' => $milk->id, 'batch_number' => 'LATE', 'quantity' => 6, 'expiry_date' => now()->addDays(20)]);
        $this->reservations()->reserve($milk, null, 3, 'kit', 'RUN', batch: $early);
        $line = fn (int $qty, array $extra = []) => $extra + [
            'customer_name' => 'M', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $milk->id, 'quantity' => $qty, 'unit_price' => '1.00']],
        ];

        // FEFO would take all 4 of EARLY; 3 of them are held.
        $this->service()->create($line(2), $this->admin);

        $this->assertSame(3, (int) $early->fresh()->quantity);
        $this->assertSame(5, (int) $late->fresh()->quantity);

        // The consumer draws its own held units.
        $this->service()->create($line(3, ['reservation_owner' => 'kit', 'reservation_reference' => 'RUN']), $this->admin);
        $this->assertSame(0, (int) $early->fresh()->quantity);
        $this->assertSame(5, (int) $late->fresh()->quantity);
    }
}
