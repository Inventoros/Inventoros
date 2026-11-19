<?php

declare(strict_types=1);

namespace Tests\Feature\Backorders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Support\BackorderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackorderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'BO Lifecycle Org',
            'email' => 'bo-life@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->creator = User::create([
            'name' => 'Creator',
            'email' => 'creator@bolife.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->enableBackorders(true);
    }

    private function enableBackorders(bool $enabled): void
    {
        $settings = $this->organization->settings ?? [];
        $settings[BackorderSettings::KEY] = ['enabled' => $enabled, 'default_allow' => false];
        $this->organization->update(['settings' => $settings]);
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    private function product(int $stock): Product
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

    private function order(Product $product, int $quantity): Order
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

    public function test_cancel_restocks_only_the_fulfilled_portion(): void
    {
        $product = $this->product(stock: 3);
        $order = $this->order($product, 5); // fulfilled 3, backordered 2
        $this->assertSame(0, (int) $product->fresh()->stock);

        $this->orders()->cancel($order->fresh());

        // Only the 3 that actually left stock come back, not the 2 backordered.
        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $order->items()->first()->quantity_backordered);
        $this->assertNull($order->fresh()->backordered_at);
    }

    public function test_delete_restocks_only_the_fulfilled_portion(): void
    {
        $product = $this->product(stock: 2);
        $order = $this->order($product, 6); // fulfilled 2, backordered 4
        $this->assertSame(0, (int) $product->fresh()->stock);

        $this->orders()->restockForDeletion($order->fresh());

        $this->assertSame(2, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $order->items()->first()->quantity_backordered);
    }

    public function test_edit_recomputes_the_backorder_split(): void
    {
        $product = $this->product(stock: 5);
        $order = $this->order($product, 3); // in stock, no backorder
        $this->assertNull($order->fresh()->backordered_at);

        // Grow the line beyond available: releases 3 (stock back to 5), then
        // re-fulfils 5 and backorders 3.
        DB::transaction(fn () => $this->orders()->replaceItems($order->fresh(), [
            ['product_id' => $product->id, 'quantity' => 8, 'unit_price' => '10.00'],
        ], $this->creator));

        $item = $order->fresh()->items()->first();
        $this->assertSame(8, (int) $item->quantity);
        $this->assertSame(3, (int) $item->quantity_backordered);
        $this->assertNotNull($order->fresh()->backordered_at);
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    public function test_edit_down_into_stock_clears_the_backorder(): void
    {
        $product = $this->product(stock: 2);
        $order = $this->order($product, 6); // backordered 4
        $this->assertNotNull($order->fresh()->backordered_at);

        // Shrink to what is on hand (the released 2 make 2 available again).
        DB::transaction(fn () => $this->orders()->replaceItems($order->fresh(), [
            ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '10.00'],
        ], $this->creator));

        $this->assertSame(0, (int) $order->fresh()->items()->first()->quantity_backordered);
        $this->assertNull($order->fresh()->backordered_at);
    }

    public function test_edit_after_switch_off_keeps_the_existing_backorder(): void
    {
        $product = $this->product(stock: 2);
        $order = $this->order($product, 5); // fulfilled 2, backordered 3

        $this->enableBackorders(false);

        // Re-price the line without growing it: the 3 units already on
        // backorder stay on backorder instead of the edit being refused.
        DB::transaction(fn () => $this->orders()->replaceItems($order->fresh(), [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => '12.00'],
        ], $this->creator));

        $item = $order->fresh()->items()->first();
        $this->assertSame(3, (int) $item->quantity_backordered);
        $this->assertNotNull($order->fresh()->backordered_at);
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    public function test_edit_after_switch_off_cannot_grow_the_backorder(): void
    {
        $product = $this->product(stock: 2);
        $order = $this->order($product, 5); // fulfilled 2, backordered 3

        $this->enableBackorders(false);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        DB::transaction(fn () => $this->orders()->replaceItems($order->fresh(), [
            ['product_id' => $product->id, 'quantity' => 6, 'unit_price' => '10.00'],
        ], $this->creator));
    }

    public function test_master_switch_off_still_reconciles_existing_backorders(): void
    {
        $product = $this->product(stock: 0);
        $order = $this->order($product, 4); // fully backordered
        $this->assertNotNull($order->fresh()->backordered_at);

        // Turn the org switch off AFTER the order exists.
        $this->enableBackorders(false);

        // Stock arrives: reconciliation is data-driven, not gated on the switch.
        StockAdjustment::adjust($product->fresh(), 4, 'purchase', 'Received', null, null, actor: $this->creator);

        $this->assertSame(0, (int) $order->items()->first()->quantity_backordered);
        $this->assertNull($order->fresh()->backordered_at);
    }
}
