<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
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

    public function test_unit_price_falls_back_to_product_price_when_omitted(): void
    {
        // API callers may omit unit_price; the service uses the product price.
        $order = $this->service()->create($this->payload([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ]), $this->creator, 'api');

        $item = $order->items()->first();
        $this->assertSame('10.00', (string) $item->unit_price);
        $this->assertSame('20.00', (string) $order->subtotal);
    }

    public function test_per_line_tax_sums_into_order_tax(): void
    {
        $order = $this->service()->create($this->payload([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10.00, 'tax' => 1.50],
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10.00, 'tax' => 2.50],
            ],
        ]), $this->creator, 'api');

        $this->assertSame('4.00', (string) $order->tax);
        $this->assertSame('20.00', (string) $order->subtotal);
        $this->assertSame('24.00', (string) $order->total);
    }

    private function variantProduct(int $variantStock = 20): array
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'VAR-PROD',
            'name' => 'Variant Product',
            'price' => 50.00,
            'currency' => 'USD',
            'stock' => 999,
            'min_stock' => 0,
            'is_active' => true,
            'has_variants' => true,
        ]);

        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'sku' => 'VAR-PROD-RED',
            'title' => 'Red',
            'option_values' => ['color' => 'red'],
            'price' => 60.00,
            'stock' => $variantStock,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    public function test_variant_line_decrements_variant_stock_not_parent(): void
    {
        [$product, $variant] = $this->variantProduct(20);

        $order = $this->service()->create($this->payload([
            'items' => [
                ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 3],
            ],
        ]), $this->creator, 'api');

        // Variant stock drops; parent product stock is untouched.
        $this->assertSame(17, (int) $variant->fresh()->stock);
        $this->assertSame(999, (int) $product->fresh()->stock);

        // Line + price snapshot come from the variant.
        $item = $order->items()->first();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame('60.00', (string) $item->unit_price);

        // Ledger row is attributed to the variant.
        $adj = StockAdjustment::where('reference_id', $order->id)->first();
        $this->assertSame($variant->id, (int) $adj->product_variant_id);
        $this->assertSame(20, (int) $adj->quantity_before);
        $this->assertSame(17, (int) $adj->quantity_after);
    }

    public function test_variant_tracked_product_requires_a_variant_id(): void
    {
        [$product] = $this->variantProduct();

        $this->expectException(InvalidOrderItemException::class);
        $this->expectExceptionMessage('sold by variant');

        $this->service()->create($this->payload([
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10.00],
            ],
        ]), $this->creator);
    }

    public function test_variant_must_belong_to_the_line_product(): void
    {
        [, $variant] = $this->variantProduct();

        // $this->product is a different, non-variant product.
        $this->expectException(InvalidOrderItemException::class);
        $this->expectExceptionMessage('does not belong');

        $this->service()->create($this->payload([
            'items' => [
                ['product_id' => $this->product->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 10.00],
            ],
        ]), $this->creator);
    }

    public function test_variant_insufficient_stock_is_checked_against_variant(): void
    {
        [$product, $variant] = $this->variantProduct(5);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->service()->create($this->payload([
                'items' => [
                    ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 10],
                ],
            ]), $this->creator);
        } finally {
            $this->assertSame(5, (int) $variant->fresh()->stock);
            $this->assertSame(0, Order::where('source', 'manual')->count());
        }
    }

    public function test_throws_typed_exception_on_insufficient_stock(): void
    {
        $this->expectException(InsufficientStockException::class);

        $this->service()->create($this->payload([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5000, 'unit_price' => 10.00],
            ],
        ]), $this->creator);
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
