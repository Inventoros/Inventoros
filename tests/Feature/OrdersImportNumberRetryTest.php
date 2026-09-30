<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\OrdersImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Support\SequenceNumberRetry;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * An order-number collision during an import must restart the order's WHOLE
 * transaction, not just the savepoint around OrderService::create.
 *
 * Under MySQL's REPEATABLE READ a transaction reads from the snapshot taken
 * at its first read, so a retry inside the same transaction re-reads the same
 * MAX(order_number), picks the same number and collides again until the
 * retries run out. The import therefore lets the retry loop own the
 * transaction (SequenceNumberRetry around DB::transaction), a nested
 * SequenceNumberRetry defers to it, and the file is read without Laravel
 * Excel's own whole-file transaction around every order.
 *
 * OrdersImportSnapshotTest reproduces the real race on MySQL/PostgreSQL.
 */
class OrdersImportNumberRetryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        Product::create([
            'organization_id' => $this->org->id, 'sku' => 'WID-1', 'name' => 'Widget',
            'price' => 10, 'currency' => 'USD', 'stock' => 20, 'min_stock' => 0,
        ]);
    }

    private function csv(string ...$lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'orders.csv',
            "external_reference,order_date,customer_name,product_sku,quantity,unit_price\n".implode("\n", $lines)."\n",
        );
    }

    public function test_a_collision_restarts_the_orders_whole_transaction(): void
    {
        $baseline = DB::transactionLevel();
        $levels = [];
        Event::listen(TransactionBeginning::class, function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        // The first insert finds its number already taken (as if another
        // request had just committed it).
        $collided = false;
        Order::creating(function (Order $order) use (&$collided) {
            if ($collided) {
                return;
            }
            $collided = true;
            DB::table('orders')->insert([
                'organization_id' => $order->organization_id, 'order_number' => $order->order_number,
                'source' => 'manual', 'customer_name' => 'Someone else', 'status' => 'pending',
                'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0, 'currency' => 'USD',
                'order_date' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $import = new OrdersImport($this->admin);
        $import->importFile($this->csv('SHOP-1,2026-01-05,Acme,WID-1,2,9.50'));

        $stats = $import->getStats();
        $this->assertSame([], $stats['errors']);
        $this->assertSame(1, $stats['imported']);
        $this->assertTrue($collided);

        $order = Order::where('external_reference', 'SHOP-1')->sole();
        $this->assertCount(1, $order->items);
        $this->assertSame(18, Product::where('sku', 'WID-1')->sole()->stock);

        // The order's own transaction (directly above the test's) was begun
        // twice: the collision rolled it back and the retry started it again
        // with a fresh snapshot. Nothing wraps it.
        $this->assertSame(2, count(array_keys($levels, $baseline + 1, true)));
    }

    public function test_each_order_is_its_own_top_level_transaction(): void
    {
        $baseline = DB::transactionLevel();
        $levels = [];
        Event::listen(TransactionBeginning::class, function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $import = new OrdersImport($this->admin);
        $import->importFile($this->csv('A-1,2026-01-05,Acme,WID-1,1,5', 'A-2,2026-01-05,Acme,WID-1,1,5', 'A-3,2026-01-05,Acme,WID-1,1,5'));

        $this->assertSame(3, $import->getStats()['imported']);
        // One transaction per order at the top, none wrapping the file.
        $this->assertSame(3, count(array_keys($levels, $baseline + 1, true)));
    }

    public function test_a_nested_retry_defers_to_the_outer_one(): void
    {
        $outer = 0;
        $inner = 0;

        $result = SequenceNumberRetry::create(function () use (&$outer, &$inner) {
            $outer++;

            return SequenceNumberRetry::create(function () use ($outer, &$inner) {
                $inner++;
                if ($outer < 3) {
                    throw new QueryException('sqlite', 'insert into "orders" ...', [], tap(
                        new \PDOException('SQLSTATE[23000]: UNIQUE constraint failed'),
                        fn ($e) => $e->errorInfo = ['23000', 19, 'UNIQUE constraint failed: orders.organization_id, orders.order_number'],
                    ));
                }

                return "ok-{$outer}";
            });
        });

        // The inner loop never retried on its own: each collision went
        // straight to the outer loop, which re-ran the whole unit.
        $this->assertSame('ok-3', $result);
        $this->assertSame(3, $outer);
        $this->assertSame(3, $inner);
    }
}
