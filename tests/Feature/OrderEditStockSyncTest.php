<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Editing an order must keep the same invariants create/cancel maintain:
 * the per-location breakdown, the serial/batch records, and (for variant
 * lines) the variant's own stock — not the parent's. Previously the web edit
 * hand-rolled a per-line adjust() that touched none of these.
 */
class OrderEditStockSyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $creator;

    private ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();

        $this->org = Organization::create([
            'name' => 'Edit Org', 'email' => 'e@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@e.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'A', 'code' => 'A', 'is_active' => true,
        ]);
        $this->actingAs($this->creator);
    }

    private function service(): OrderService
    {
        return app(OrderService::class);
    }

    private function createOrder(array $items): Order
    {
        return $this->service()->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => $items,
        ], $this->creator);
    }

    private function edit(Order $order, array $items): void
    {
        DB::transaction(fn () => $this->service()->replaceItems($order->fresh(), $items));
    }

    private function binAt(Product $product): int
    {
        return (int) ProductLocationStock::where('product_id', $product->id)
            ->where('location_id', $this->location->id)->value('quantity');
    }

    public function test_editing_a_quantity_up_keeps_the_location_bin_in_sync(): void
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'P-1', 'name' => 'P',
            'price' => 10, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0,
            'location_id' => $this->location->id, 'is_active' => true,
        ]);

        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10.00]]);
        $this->assertSame(98, (int) $product->fresh()->stock);
        $this->assertSame(98, $this->binAt($product));

        // Increase the line 2 -> 5: total and bin both fall to 95 (no drift).
        $this->edit($order, [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10.00]]);

        $this->assertSame(95, (int) $product->fresh()->stock);
        $this->assertSame(95, $this->binAt($product));
    }

    public function test_editing_a_quantity_down_releases_the_extra_serials(): void
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SER-1', 'name' => 'Serialed',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'tracking_type' => 'serial', 'is_active' => true,
        ]);
        for ($i = 1; $i <= 5; $i++) {
            ProductSerial::create([
                'organization_id' => $this->org->id, 'product_id' => $product->id,
                'serial_number' => "SN-{$i}", 'status' => 'available',
            ]);
        }

        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10.00]]);
        $this->assertSame(3, ProductSerial::where('product_id', $product->id)->where('status', 'sold')->count());

        // Reduce the line 3 -> 1: exactly one serial stays sold, the rest return.
        $this->edit($order, [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10.00]]);

        $this->assertSame(4, (int) $product->fresh()->stock);
        $this->assertSame(1, ProductSerial::where('product_id', $product->id)->where('status', 'sold')->count());
        $this->assertSame(4, ProductSerial::where('product_id', $product->id)->where('status', 'available')->count());
    }

    public function test_editing_a_variant_line_adjusts_the_variant_not_the_parent(): void
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'VAR-P', 'name' => 'Varied',
            'price' => 10, 'currency' => 'USD', 'stock' => 50, 'min_stock' => 0,
            'has_variants' => true, 'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id,
            'sku' => 'VAR-M', 'title' => 'Medium', 'option_values' => ['size' => 'M'],
            'price' => 10.00, 'stock' => 10, 'is_active' => true,
        ]);

        $order = $this->createOrder([[
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'quantity' => 2, 'unit_price' => 10.00,
        ]]);
        $this->assertSame(8, (int) $variant->fresh()->stock);
        $this->assertSame(50, (int) $product->fresh()->stock);

        // Edit the variant line 2 -> 4: the variant absorbs the change; the
        // parent's own stock is never touched.
        $this->edit($order, [[
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'quantity' => 4, 'unit_price' => 10.00,
        ]]);

        $this->assertSame(6, (int) $variant->fresh()->stock);
        $this->assertSame(50, (int) $product->fresh()->stock);
    }
}
