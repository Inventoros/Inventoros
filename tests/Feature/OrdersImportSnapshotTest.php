<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\OrdersImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The real race behind OrdersImportNumberRetryTest, on a server database.
 *
 * While an import is creating an order, a second connection commits another
 * order holding the number the import just generated. Under MySQL's
 * REPEATABLE READ the import's transaction cannot see that row: retrying
 * inside the same transaction regenerates the same number and collides every
 * time. Only restarting the transaction (a fresh snapshot) moves past it.
 *
 * Needs committed data a second connection can see, so it does not run in
 * the usual per-test transaction and rebuilds the schema afterwards. Skipped
 * on SQLite, where a second connection cannot reach the in-memory database.
 */
class OrdersImportSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Commit for real: the second connection must see this test's rows.
     *
     * @return array<int, string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Needs a server database that a second connection can share (runs on the MySQL and PostgreSQL CI legs).');
        }

        config(['database.connections.import_race' => config('database.connections.'.DB::getDefaultConnection())]);
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::disconnect('import_race');
            // Nothing here was rolled back: leave an empty schema behind for
            // the tests that follow.
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        }

        parent::tearDown();
    }

    public function test_an_import_survives_a_number_taken_by_a_concurrent_commit(): void
    {
        $org = Organization::create(['name' => 'Race Org', 'email' => 'race@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'race-admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);
        Product::create([
            'organization_id' => $org->id, 'sku' => 'RACE-1', 'name' => 'Racer',
            'price' => 10, 'currency' => 'USD', 'stock' => 20, 'min_stock' => 0,
        ]);

        $taken = null;
        Order::creating(function (Order $order) use (&$taken) {
            if ($taken !== null) {
                return;
            }
            $taken = $order->order_number;

            // Another request commits an order with this very number. The
            // import's transaction has already read (and so fixed its
            // snapshot) by now.
            DB::connection('import_race')->table('orders')->insert([
                'organization_id' => $order->organization_id, 'order_number' => $order->order_number,
                'source' => 'manual', 'customer_name' => 'Concurrent', 'status' => 'pending',
                'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0, 'currency' => 'USD',
                'order_date' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $import = new OrdersImport($admin);
        $import->importFile(UploadedFile::fake()->createWithContent(
            'orders.csv',
            "external_reference,order_date,customer_name,product_sku,quantity,unit_price\nRACE-A,2026-01-05,Acme,RACE-1,2,9.50\n",
        ));

        $stats = $import->getStats();
        $this->assertSame([], $stats['errors']);
        $this->assertSame(1, $stats['imported']);

        $imported = Order::withoutGlobalScopes()->where('external_reference', 'RACE-A')->sole();
        $this->assertNotNull($taken);
        $this->assertNotSame($taken, $imported->order_number);
        $this->assertSame(2, Order::withoutGlobalScopes()->where('organization_id', $org->id)->count());
        $this->assertSame(18, Product::withoutGlobalScopes()->where('sku', 'RACE-1')->sole()->stock);
    }
}
