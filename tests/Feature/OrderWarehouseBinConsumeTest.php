<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * An order fulfilled from a warehouse draws its units from that warehouse's
 * bins first. Only when those run out does it fall back to the other bins in
 * the usual fulfilment order (warehouse priority, then the primary location,
 * then the fullest bin). An order with no warehouse keeps the priority order.
 * Either way SUM(bins) stays equal to products.stock.
 */
final class OrderWarehouseBinConsumeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $creator;

    private Warehouse $east;

    private Warehouse $west;

    private ProductLocation $eastShelf;

    private ProductLocation $westShelf;

    private ProductLocation $westOverflow;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();

        $this->org = Organization::create(['name' => 'WH Org', 'email' => 'wh@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@wh.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        // East outranks West, and the product's primary location is in East,
        // so without an order warehouse East always drains first.
        $this->east = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'East', 'code' => 'EAST', 'priority' => 10, 'is_active' => true]);
        $this->west = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'West', 'code' => 'WEST', 'priority' => 0, 'is_active' => true]);

        $this->eastShelf = $this->location('E-1', $this->east);
        $this->westShelf = $this->location('W-1', $this->west);
        $this->westOverflow = $this->location('W-2', $this->west);

        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'WH-1', 'name' => 'Widget',
            'price' => 10, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0,
            'location_id' => $this->eastShelf->id, 'is_active' => true,
        ]);
        $this->bin($this->eastShelf, 50);
        $this->bin($this->westShelf, 30);
        $this->bin($this->westOverflow, 20);

        $this->actingAs($this->creator);
    }

    private function location(string $code, Warehouse $warehouse): ProductLocation
    {
        return ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => $code, 'code' => $code,
            'warehouse_id' => $warehouse->id, 'is_active' => true,
        ]);
    }

    private function bin(ProductLocation $location, int $quantity): void
    {
        ProductLocationStock::create([
            'organization_id' => $this->org->id, 'product_id' => $this->product->id,
            'location_id' => $location->id, 'quantity' => $quantity,
        ]);
    }

    private function order(int $qty, ?Warehouse $warehouse): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'warehouse_id' => $warehouse?->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty, 'unit_price' => 10]],
        ], $this->creator);
    }

    /**
     * @return array<string, int>
     */
    private function bins(): array
    {
        return [
            'E-1' => (int) ProductLocationStock::where('location_id', $this->eastShelf->id)->value('quantity'),
            'W-1' => (int) ProductLocationStock::where('location_id', $this->westShelf->id)->value('quantity'),
            'W-2' => (int) ProductLocationStock::where('location_id', $this->westOverflow->id)->value('quantity'),
        ];
    }

    private function assertBinsMatchStock(): void
    {
        $this->assertSame(
            (int) $this->product->fresh()->stock,
            (int) ProductLocationStock::where('product_id', $this->product->id)->sum('quantity'),
            'SUM(bins) must equal products.stock',
        );
    }

    public function test_an_order_draws_from_its_own_warehouse_first(): void
    {
        $this->order(25, $this->west);

        // West's fullest bin first; East (higher priority, primary) untouched.
        $this->assertSame(['E-1' => 50, 'W-1' => 5, 'W-2' => 20], $this->bins());
        $this->assertSame(75, (int) $this->product->fresh()->stock);
        $this->assertBinsMatchStock();
    }

    public function test_a_warehouse_id_posted_as_a_string_is_honoured(): void
    {
        app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'warehouse_id' => (string) $this->west->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 25, 'unit_price' => 10]],
        ], $this->creator);

        $this->assertSame(['E-1' => 50, 'W-1' => 5, 'W-2' => 20], $this->bins());
    }

    public function test_an_order_spanning_its_warehouse_drains_it_then_falls_back_by_priority(): void
    {
        $this->order(60, $this->west);

        // West's 50 units first, the last 10 from East.
        $this->assertSame(['E-1' => 40, 'W-1' => 0, 'W-2' => 0], $this->bins());
        $this->assertSame(40, (int) $this->product->fresh()->stock);
        $this->assertBinsMatchStock();
    }

    public function test_an_order_without_a_warehouse_keeps_the_priority_order(): void
    {
        $this->order(60, null);

        $this->assertSame(['E-1' => 0, 'W-1' => 20, 'W-2' => 20], $this->bins());
        $this->assertBinsMatchStock();
    }

    public function test_an_order_from_a_warehouse_holding_none_falls_back_by_priority(): void
    {
        $north = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'North', 'code' => 'NORTH', 'priority' => 0, 'is_active' => true]);

        $this->order(10, $north);

        $this->assertSame(['E-1' => 40, 'W-1' => 30, 'W-2' => 20], $this->bins());
        $this->assertBinsMatchStock();
    }

    public function test_editing_an_orders_lines_redraws_from_its_warehouse(): void
    {
        $order = $this->order(10, $this->west);
        $this->assertSame(['E-1' => 50, 'W-1' => 20, 'W-2' => 20], $this->bins());

        app(OrderService::class)->replaceItems($order->fresh(), [
            ['product_id' => $this->product->id, 'quantity' => 30, 'unit_price' => 10],
        ], $this->creator);

        // The release puts the 10 back in the primary bin (East); the new 30
        // come out of West.
        $this->assertSame(70, (int) $this->product->fresh()->stock);
        $this->assertSame(10, $this->bins()['W-1'] + $this->bins()['W-2']);
        $this->assertSame(60, $this->bins()['E-1']);
        $this->assertBinsMatchStock();
    }
}
