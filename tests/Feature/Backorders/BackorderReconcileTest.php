<?php

declare(strict_types=1);

namespace Tests\Feature\Backorders;

use App\Listeners\BackorderReconcileSubscriber;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\BackorderService;
use App\Services\OrderService;
use App\Services\ProductLocationStockService;
use App\Support\BackorderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackorderReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'BO Reconcile Org',
            'email' => 'bo-reconcile@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->creator = User::create([
            'name' => 'Creator',
            'email' => 'creator@boreconcile.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $settings = $this->organization->settings ?? [];
        $settings[BackorderSettings::KEY] = ['enabled' => true, 'default_allow' => false];
        $this->organization->update(['settings' => $settings]);
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    private function backorders(): BackorderService
    {
        return app(BackorderService::class);
    }

    private function product(int $stock = 0): Product
    {
        return Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'BO-'.uniqid(),
            'name' => 'BO Product',
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => $stock,
            'is_active' => true,
            'allow_backorder' => true,
        ]);
    }

    private function backorder(Product $product, int $quantity): Order
    {
        return $this->orders()->create([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 10.00],
            ],
        ], $this->creator);
    }

    /** Book stock through the ledger — this fires the stock_adjusted hook, so a
     *  registered listener reconciles automatically (the real receiving path). */
    private function receiveStock(Product $product, int $quantity): StockAdjustment
    {
        return StockAdjustment::adjust(
            $product->fresh(), $quantity, 'purchase', 'Stock received', null, null, actor: $this->creator,
        );
    }

    /** Make stock available WITHOUT firing the hook, to isolate the service. */
    private function rawStock(Product $product, int $quantity): void
    {
        $product->increment('stock', $quantity);
        app(ProductLocationStockService::class)->receive($product->fresh(), $quantity);
    }

    public function test_stock_arrival_fulfills_oldest_order_first(): void
    {
        $product = $this->product(stock: 0);
        $older = $this->backorder($product, 2);
        $newer = $this->backorder($product, 2);

        $this->receiveStock($product, 3);

        $this->assertSame(0, (int) $older->items()->first()->quantity_backordered);
        $this->assertNull($older->fresh()->backordered_at);
        $this->assertSame(1, (int) $newer->items()->first()->quantity_backordered);
        $this->assertNotNull($newer->fresh()->backordered_at);
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    public function test_partial_arrival_leaves_remainder(): void
    {
        $product = $this->product(stock: 0);
        $order = $this->backorder($product, 5);

        $this->receiveStock($product, 3);

        $this->assertSame(2, (int) $order->items()->first()->quantity_backordered);
        $this->assertNotNull($order->fresh()->backordered_at);
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    public function test_clears_backordered_at_only_when_all_lines_zero(): void
    {
        $productA = $this->product(stock: 0);
        $productB = $this->product(stock: 0);

        $order = $this->orders()->create([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2, 'unit_price' => 10.00],
                ['product_id' => $productB->id, 'quantity' => 2, 'unit_price' => 10.00],
            ],
        ], $this->creator);

        $this->receiveStock($productA, 2);
        $this->assertNotNull($order->fresh()->backordered_at, 'Still backordered while product B is short.');

        $this->receiveStock($productB, 2);
        $this->assertNull($order->fresh()->backordered_at, 'Cleared once every line is fulfilled.');
    }

    public function test_reconcile_for_product_returns_units_and_respects_available(): void
    {
        $product = $this->product(stock: 0);
        $order = $this->backorder($product, 5);

        // Stock becomes available without the hook; reconcile explicitly.
        $this->rawStock($product, 3);
        $fulfilled = $this->backorders()->reconcileForProduct($this->organization->id, $product->id, null);

        $this->assertSame(3, $fulfilled);
        $this->assertSame(2, (int) $order->items()->first()->quantity_backordered);
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    public function test_reconcile_order_fulfills_that_order(): void
    {
        $product = $this->product(stock: 0);
        $order = $this->backorder($product, 4);

        $this->rawStock($product, 4);
        $this->backorders()->reconcileOrder($order->fresh(), $this->creator);

        $this->assertSame(0, (int) $order->items()->first()->quantity_backordered);
        $this->assertNull($order->fresh()->backordered_at);
    }

    public function test_reconcile_skips_cancelled_order(): void
    {
        $product = $this->product(stock: 0);
        $order = $this->backorder($product, 2);
        $this->orders()->cancel($order->fresh());

        $this->rawStock($product, 5);
        $fulfilled = $this->backorders()->reconcileForProduct($this->organization->id, $product->id, null);

        // Reconcile only touches OPEN orders, so it fulfils nothing here; and
        // cancel() has already cleared the (now moot) backorder.
        $this->assertSame(0, $fulfilled);
        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame(0, (int) $order->items()->first()->quantity_backordered);
    }

    public function test_listener_ignores_a_decrease(): void
    {
        $product = $this->product(stock: 0);
        $order = $this->backorder($product, 2);

        // Stock is available (no hook), but the triggering adjustment is a decrease.
        $this->rawStock($product, 2);
        $decrease = new StockAdjustment([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'adjustment_quantity' => -1,
        ]);
        BackorderReconcileSubscriber::onStockAdjusted($decrease, $product->fresh());

        $this->assertSame(2, (int) $order->items()->first()->quantity_backordered, 'A decrease must not reconcile.');

        // Proof the setup was reconcilable: an increase-driven reconcile fulfils it.
        $this->backorders()->reconcileForProduct($this->organization->id, $product->id, null);
        $this->assertSame(0, (int) $order->items()->first()->quantity_backordered);
    }

    public function test_command_sweeps_outstanding_backorders(): void
    {
        $product = $this->product(stock: 0);
        $order = $this->backorder($product, 4);

        // Stranded backorder: stock present but not yet reconciled.
        $this->rawStock($product, 4);

        $this->artisan('inventory:reconcile-backorders')->assertSuccessful();

        $this->assertSame(0, (int) $order->items()->first()->quantity_backordered);
        $this->assertNull($order->fresh()->backordered_at);
    }
}
