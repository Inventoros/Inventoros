<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductSerial;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Serial-tracked products should have their serials consumed as orders are
 * fulfilled and returned as orders are unwound — the first step toward serials
 * being the source of truth for stock. Allocation is best-effort during the
 * transition: an order for a product whose serials are not fully populated
 * still succeeds, untouched.
 */
class SerialAllocationOnFulfillmentTest extends TestCase
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
            'name' => 'Alloc Org', 'email' => 'a@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@a.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        // OrderService::create attributes to the passed creator, but the
        // cancel/restock path writes adjustments as the authenticated user.
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

    private function order(Product $product, int $qty): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 10.00]],
        ], $this->creator);
    }

    private function availableCount(Product $product): int
    {
        return ProductSerial::where('product_id', $product->id)->where('status', 'available')->count();
    }

    public function test_fulfilling_an_order_allocates_serials_and_pins_them_to_the_line(): void
    {
        $product = $this->serialProduct(stock: 5, serials: 5);

        $order = $this->order($product, 3);
        $orderItem = $order->items()->first();

        // Total stock decremented as always...
        $this->assertSame(2, (int) $product->fresh()->stock);
        // ...and exactly 3 serials are now sold and pinned to the line, leaving
        // the available count equal to the recorded stock.
        $this->assertSame(2, $this->availableCount($product));
        $this->assertSame(3, ProductSerial::where('order_item_id', $orderItem->id)
            ->where('status', 'sold')->count());
    }

    public function test_cancelling_an_order_releases_its_serials(): void
    {
        $product = $this->serialProduct(stock: 5, serials: 5);
        $order = $this->order($product, 3);

        app(OrderService::class)->cancel($order);

        // Stock restored and every serial is available again with its link cleared.
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(5, $this->availableCount($product));
        $this->assertSame(0, ProductSerial::whereNotNull('order_item_id')->count());
    }

    public function test_order_succeeds_untouched_when_serials_are_not_fully_populated(): void
    {
        // Recorded stock is 5 but only 2 serials exist (drift): an order for 3
        // must still succeed, and the under-populated serials are left alone.
        $product = $this->serialProduct(stock: 5, serials: 2);

        $order = $this->order($product, 3);

        $this->assertSame(2, (int) $product->fresh()->stock);
        // Best-effort skip: nothing was allocated, nothing was pinned.
        $this->assertSame(2, $this->availableCount($product));
        $this->assertSame(0, ProductSerial::where('order_item_id', $order->items()->first()->id)->count());
    }
}
