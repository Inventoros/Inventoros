<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fulfilling an order must draw the sold units out of the product's location
 * bins, not just the global total — otherwise a bin ends up claiming more than
 * exists (SUM(bins) > stock).
 */
class OrderDecrementLocationBinsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $creator;

    private ProductLocation $locationA;

    private ProductLocation $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->org = Organization::create([
            'name' => 'Bin Org', 'email' => 'bin@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@bin.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->locationA = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'A', 'code' => 'A', 'is_active' => true,
        ]);
        $this->locationB = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'B', 'code' => 'B', 'is_active' => true,
        ]);

        $this->actingAs($this->creator);
    }

    private function product(int $stock, ?int $locationId): Product
    {
        return Product::create([
            'organization_id' => $this->org->id, 'sku' => 'P-1', 'name' => 'P',
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'location_id' => $locationId, 'is_active' => true,
        ]);
    }

    private function order(Product $product, int $qty): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 10.00]],
        ], $this->creator);
    }

    private function binAt(Product $product, ProductLocation $loc): ?int
    {
        $q = ProductLocationStock::where('product_id', $product->id)->where('location_id', $loc->id)->value('quantity');

        return $q === null ? null : (int) $q;
    }

    public function test_fulfilment_draws_from_the_primary_location_bin(): void
    {
        $product = $this->product(stock: 100, locationId: $this->locationA->id);

        $this->order($product, 30);

        // The unbinned product is lazily seeded at its location, then drawn down,
        // so the bin equals the new total (no over-claim).
        $this->assertSame(70, (int) $product->fresh()->stock);
        $this->assertSame(70, $this->binAt($product, $this->locationA));
    }

    public function test_fulfilment_drains_the_primary_bin_first_then_spills(): void
    {
        $product = $this->product(stock: 100, locationId: $this->locationA->id);
        // 10 at the primary (A), 90 at B.
        ProductLocationStock::create(['organization_id' => $this->org->id, 'product_id' => $product->id, 'location_id' => $this->locationA->id, 'quantity' => 10]);
        ProductLocationStock::create(['organization_id' => $this->org->id, 'product_id' => $product->id, 'location_id' => $this->locationB->id, 'quantity' => 90]);

        $this->order($product, 50);

        // Primary drained first (10 → 0), the rest spilled to B (90 → 50).
        $this->assertSame(0, $this->binAt($product, $this->locationA));
        $this->assertSame(50, $this->binAt($product, $this->locationB));
        $this->assertSame(50, (int) $product->fresh()->stock);
    }

    public function test_cancelling_returns_units_to_the_primary_bin(): void
    {
        $product = $this->product(stock: 100, locationId: $this->locationA->id);
        $order = $this->order($product, 30); // A: 100 → 70

        app(OrderService::class)->cancel($order);

        $this->assertSame(100, (int) $product->fresh()->stock);
        $this->assertSame(100, $this->binAt($product, $this->locationA));
    }

    public function test_unlocated_product_order_is_unaffected(): void
    {
        $product = $this->product(stock: 100, locationId: null);

        $this->order($product, 30);

        // No location, so no bins — the total still moves, nothing to track.
        $this->assertSame(70, (int) $product->fresh()->stock);
        $this->assertSame(0, ProductLocationStock::where('product_id', $product->id)->count());
    }
}
