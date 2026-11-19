<?php

declare(strict_types=1);

namespace Tests\Feature\Backorders;

use App\Exceptions\InsufficientStockException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;
use App\Services\OrderService;
use App\Support\BackorderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreateBackorderTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'BO Create Org',
            'email' => 'bo-create@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->creator = User::create([
            'name' => 'Creator',
            'email' => 'creator@bocreate.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
    }

    private function service(): OrderService
    {
        return app(OrderService::class);
    }

    private function enableBackorders(bool $enabled = true): void
    {
        $settings = $this->organization->settings ?? [];
        $settings[BackorderSettings::KEY] = ['enabled' => $enabled, 'default_allow' => false];
        $this->organization->update(['settings' => $settings]);
    }

    private function product(int $stock, bool $allowBackorder): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'BO-'.uniqid(),
            'name' => 'BO Product',
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => $stock,
            'is_active' => true,
            'allow_backorder' => $allowBackorder,
        ]);
    }

    private function createOrder(Product $product, int $quantity): Order
    {
        return $this->service()->create([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 10.00],
            ],
        ], $this->creator);
    }

    public function test_short_line_with_backorder_on_saves_with_split(): void
    {
        $this->enableBackorders();
        $product = $this->product(stock: 3, allowBackorder: true);

        $order = $this->createOrder($product, 5);

        $item = $order->items()->first();
        $this->assertSame(5, (int) $item->quantity);
        $this->assertSame(2, (int) $item->quantity_backordered);
        $this->assertSame(3, (int) $item->quantity_fulfilled);
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertNotNull($order->fresh()->backordered_at);

        $adjustments = StockAdjustment::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', 'order_fulfillment')
            ->get();
        $this->assertCount(1, $adjustments);
        $this->assertSame(-3, (int) $adjustments->first()->adjustment_quantity);
        $this->assertSame(0, (int) $adjustments->first()->quantity_after);
    }

    public function test_short_line_with_product_flag_off_still_throws(): void
    {
        $this->enableBackorders();
        $product = $this->product(stock: 3, allowBackorder: false);

        try {
            $this->createOrder($product, 5);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(0, Order::where('organization_id', $this->organization->id)->count());
    }

    public function test_master_switch_off_still_throws(): void
    {
        $this->enableBackorders(false);
        $product = $this->product(stock: 3, allowBackorder: true);

        try {
            $this->createOrder($product, 5);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(3, (int) $product->fresh()->stock);
    }

    public function test_fully_backordered_line_saved(): void
    {
        $this->enableBackorders();
        $product = $this->product(stock: 0, allowBackorder: true);

        $order = $this->createOrder($product, 4);

        $item = $order->items()->first();
        $this->assertSame(4, (int) $item->quantity);
        $this->assertSame(4, (int) $item->quantity_backordered);
        $this->assertSame(0, (int) $item->quantity_fulfilled);
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertNotNull($order->fresh()->backordered_at);

        $this->assertSame(0, StockAdjustment::where('reference_id', $order->id)
            ->where('type', 'order_fulfillment')
            ->count());
    }

    public function test_two_lines_same_product_partial(): void
    {
        $this->enableBackorders();
        $product = $this->product(stock: 3, allowBackorder: true);

        $order = $this->service()->create([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10.00],
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10.00],
            ],
        ], $this->creator);

        $items = $order->items()->orderBy('id')->get();
        $this->assertSame([2, 0], [(int) $items[0]->quantity, (int) $items[0]->quantity_backordered]);
        $this->assertSame([3, 2], [(int) $items[1]->quantity, (int) $items[1]->quantity_backordered]);
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertNotNull($order->fresh()->backordered_at);
    }

    public function test_existing_create_unchanged_when_off(): void
    {
        // Switch off, fully in stock: behaves exactly as before.
        $product = $this->product(stock: 100, allowBackorder: true);

        $order = $this->createOrder($product, 3);

        $item = $order->items()->first();
        $this->assertSame(0, (int) $item->quantity_backordered);
        $this->assertSame(97, (int) $product->fresh()->stock);
        $this->assertNull($order->fresh()->backordered_at);
    }
}
