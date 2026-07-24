<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Support\SequenceNumberRetry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class OrderNumberRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_order_create_retries_on_order_number_collision(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create([
            'name' => 'RetryOrg', 'email' => 'ro@test.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);

        $product = Product::create([
            'organization_id' => $org->id,
            'sku' => 'RACE-SKU-001',
            'name' => 'Race Product',
            'price' => 10, 'currency' => 'USD',
            'stock' => 100, 'min_stock' => 0,
            'is_active' => true,
        ]);

        // Pre-create an order at the number the generator will pick next.
        // Without SequenceNumberRetry, the API store would race the
        // generator against the existing row and fail with a 500.
        $today = now()->format('Ymd');
        Order::create([
            'organization_id' => $org->id,
            'order_number' => "ORD-{$today}-0001",
            'source' => 'manual',
            'customer_name' => 'Existing',
            'status' => 'pending',
            'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0,
            'currency' => 'USD',
            'order_date' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'New Order',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10.0]],
        ]);

        $response->assertStatus(201);

        $created = Order::where('customer_name', 'New Order')->first();
        $this->assertNotNull($created);
        // The retry should have produced a different number than the
        // pre-existing 0001.
        $this->assertNotSame("ORD-{$today}-0001", $created->order_number);
        $this->assertStringStartsWith("ORD-{$today}-", $created->order_number);
    }

    public function test_order_number_generation_accounts_for_soft_deleted_orders(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create([
            'name' => 'SoftOrg', 'email' => 'so@test.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);
        $product = Product::create([
            'organization_id' => $org->id, 'sku' => 'SD-SKU-001', 'name' => 'SD Product',
            'price' => 10, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);

        $today = now()->format('Ymd');

        // Soft-delete the highest-numbered order of the day (the most common
        // thing to delete). The unique index still counts the trashed row, so
        // a generator that reads MAX() through the default scope regenerates
        // the same number forever and locks the org out of creating orders.
        $order = Order::create([
            'organization_id' => $org->id,
            'order_number' => "ORD-{$today}-0001",
            'source' => 'manual', 'customer_name' => 'Deleted', 'status' => 'pending',
            'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0,
            'currency' => 'USD', 'order_date' => now(),
        ]);
        $order->delete();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'After Delete',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10.0]],
        ]);

        $response->assertStatus(201);

        $created = Order::where('customer_name', 'After Delete')->firstOrFail();
        $this->assertNotSame("ORD-{$today}-0001", $created->order_number);
    }

    public function test_retry_helper_recovers_from_unique_violation(): void
    {
        $attempts = 0;

        $result = SequenceNumberRetry::create(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new QueryException(
                    'default',
                    'INSERT INTO orders ...',
                    [],
                    tap(new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed', 23000), function ($e) {
                        $e->errorInfo = ['23000', 19, 'UNIQUE constraint failed'];
                    })
                );
            }

            return 'ok-after-'.$attempts;
        });

        $this->assertSame('ok-after-3', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_retry_helper_gives_up_after_max_attempts_with_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to allocate a unique sequence number');

        SequenceNumberRetry::create(function () {
            throw new QueryException(
                'default',
                'INSERT ...',
                [],
                tap(new \PDOException('SQLSTATE[23000]: collision', 23000), function ($e) {
                    $e->errorInfo = ['23000', 19, 'collision'];
                })
            );
        }, maxAttempts: 2);
    }

    public function test_retry_helper_propagates_non_unique_query_exceptions(): void
    {
        $this->expectException(QueryException::class);

        SequenceNumberRetry::create(function () {
            throw new QueryException(
                'default',
                'SELECT 1',
                [],
                tap(new \PDOException('SQLSTATE[42S22]: Column not found', 42), function ($e) {
                    $e->errorInfo = ['42S22', 1054, 'Column not found'];
                })
            );
        });
    }
}
