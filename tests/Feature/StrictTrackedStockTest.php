<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductSerial;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * In strict mode the tracking records are authoritative: an order for a
 * serial/batch-tracked product must be fully covered by serials/batch quantity
 * or it is rejected, even when products.stock could cover it. Off (the default)
 * stays best-effort.
 */
class StrictTrackedStockTest extends TestCase
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
            'name' => 'Strict Org', 'email' => 's@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@s.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->actingAs($this->creator);
    }

    private function serialProduct(int $stock, int $serials): Product
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SER-1', 'name' => 'Serialed',
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'tracking_type' => 'serial', 'is_active' => true,
        ]);
        for ($i = 1; $i <= $serials; $i++) {
            ProductSerial::create([
                'organization_id' => $this->org->id, 'product_id' => $product->id,
                'serial_number' => "SN-{$i}", 'status' => 'available',
            ]);
        }

        return $product;
    }

    private function batchProduct(int $stock, int $batchQty): Product
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'BAT-1', 'name' => 'Batched',
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'tracking_type' => 'batch', 'is_active' => true,
        ]);
        ProductBatch::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'batch_number' => 'B1', 'quantity' => $batchQty, 'expiry_date' => '2026-06-01',
        ]);

        return $product;
    }

    private function order(Product $product, int $qty): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 10.00]],
        ], $this->creator);
    }

    public function test_strict_mode_rejects_an_order_an_under_populated_serial_product_cannot_cover(): void
    {
        config(['inventory.strict_tracked_stock' => true]);
        // Stock 5 could cover 3, but only 2 serials are tracked.
        $product = $this->serialProduct(stock: 5, serials: 2);

        try {
            $this->order($product, 3);
            $this->fail('Expected InsufficientStockException in strict mode.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('serials', $e->getMessage());
        }

        // Rolled back cleanly: no order, stock and serials untouched.
        $this->assertSame(0, Order::count());
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(2, ProductSerial::where('product_id', $product->id)->where('status', 'available')->count());
    }

    public function test_strict_mode_rejects_an_order_an_under_populated_batch_product_cannot_cover(): void
    {
        config(['inventory.strict_tracked_stock' => true]);
        $product = $this->batchProduct(stock: 10, batchQty: 3);

        try {
            $this->order($product, 5);
            $this->fail('Expected InsufficientStockException in strict mode.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('batch', $e->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) ProductBatch::where('product_id', $product->id)->sum('quantity'));
    }

    public function test_strict_mode_allows_a_fully_populated_tracked_order(): void
    {
        config(['inventory.strict_tracked_stock' => true]);
        $product = $this->serialProduct(stock: 5, serials: 5);

        $this->order($product, 3);

        $this->assertSame(2, (int) $product->fresh()->stock);
        $this->assertSame(3, ProductSerial::where('product_id', $product->id)->where('status', 'sold')->count());
    }

    public function test_default_best_effort_mode_still_lets_under_populated_orders_through(): void
    {
        // Default (strict off): the under-populated order succeeds untouched.
        $product = $this->serialProduct(stock: 5, serials: 2);

        $this->order($product, 3);

        $this->assertSame(2, (int) $product->fresh()->stock);
        $this->assertSame(2, ProductSerial::where('product_id', $product->id)->where('status', 'available')->count());
    }
}
