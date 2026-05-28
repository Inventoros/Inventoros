<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $creator;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Svc Org',
            'email' => 'svc@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SVC-001',
            'name' => 'Svc Product',
            'price' => 10.00,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $this->creator = User::create([
            'name' => 'Creator',
            'email' => 'creator@svc.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
    }

    private function service(): OrderService
    {
        return app(OrderService::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Acme',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10.00],
            ],
        ], $overrides);
    }

    public function test_create_persists_order_with_org_creator_and_source(): void
    {
        $order = $this->service()->create($this->payload(), $this->creator, 'manual');

        $this->assertSame($this->organization->id, $order->organization_id);
        $this->assertSame($this->creator->id, $order->created_by);
        $this->assertSame('manual', $order->source);
        $this->assertSame('pending', $order->approval_status);
        $this->assertNotEmpty($order->order_number);
        $this->assertSame('30.00', (string) $order->subtotal);
    }

    public function test_create_writes_line_items_and_decrements_stock(): void
    {
        $order = $this->service()->create($this->payload(), $this->creator);

        $this->assertCount(1, $order->items()->get());
        $this->assertSame(97, (int) $this->product->fresh()->stock);
    }

    public function test_create_writes_stock_adjustment_ledger(): void
    {
        $order = $this->service()->create($this->payload(), $this->creator);

        $adjustment = StockAdjustment::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->first();

        $this->assertNotNull($adjustment);
        $this->assertSame('order_fulfillment', $adjustment->type);
        $this->assertSame(100, (int) $adjustment->quantity_before);
        $this->assertSame(97, (int) $adjustment->quantity_after);
        $this->assertSame($this->creator->id, $adjustment->user_id);
    }

    public function test_create_threads_running_stock_for_repeated_product(): void
    {
        // Two line items for the same product must validate and decrement
        // against one running balance, with a faithful ledger.
        $order = $this->service()->create($this->payload([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10.00],
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10.00],
            ],
        ]), $this->creator);

        $this->assertSame(93, (int) $this->product->fresh()->stock);
        $this->assertCount(2, $order->items()->get());

        $afters = StockAdjustment::where('reference_id', $order->id)
            ->orderBy('id')
            ->pluck('quantity_after')
            ->map(fn ($v) => (int) $v)
            ->all();
        $this->assertSame([98, 93], $afters);
    }

    public function test_create_rejects_insufficient_stock_and_rolls_back(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient stock');

        try {
            $this->service()->create($this->payload([
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 1000, 'unit_price' => 10.00],
                ],
            ]), $this->creator);
        } finally {
            // Nothing committed: stock untouched, no order rows.
            $this->assertSame(100, (int) $this->product->fresh()->stock);
            $this->assertSame(0, Order::count());
        }
    }
}
