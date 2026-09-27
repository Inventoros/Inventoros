<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\OrderItem;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Order lines record their unit cost at the time of sale, so margin and
 * turnover reports stop depending on today's purchase price. Rows that
 * existed before the column are backfilled from the then-current cost and
 * carry a unit_cost_backfilled_at marker.
 */
class OrderItemUnitCostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Cost Org', 'email' => 'c@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->creator = User::create([
            'name' => 'Creator', 'email' => 'creator@org.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    private function product(string $sku, ?float $cost, bool $hasVariants = false): Product
    {
        return Product::create([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => $sku, 'price' => 20,
            'purchase_price' => $cost, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => $hasVariants,
        ]);
    }

    private function variant(Product $product, string $sku, ?float $cost): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $product->id, 'sku' => $sku, 'title' => $sku,
            'option_values' => ['size' => $sku], 'price' => 25, 'purchase_price' => $cost, 'stock' => 50, 'is_active' => true,
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function createOrder(array $items)
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(), 'items' => $items,
        ], $this->creator);
    }

    public function test_a_product_line_records_the_products_purchase_price(): void
    {
        $product = $this->product('P-COST', 7.25);

        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20]]);

        $item = $order->items()->first();
        $this->assertSame('7.25', (string) $item->unit_cost);
        $this->assertNull($item->unit_cost_backfilled_at);

        // A later cost change does not rewrite history.
        $product->update(['purchase_price' => 99]);
        $this->assertSame('7.25', (string) $item->fresh()->unit_cost);
    }

    public function test_a_variant_line_prefers_the_variants_own_cost(): void
    {
        $product = $this->product('P-VAR', 5, true);
        $withCost = $this->variant($product, 'V-OWN', 8.5);
        $withoutCost = $this->variant($product, 'V-INHERIT', null);

        $order = $this->createOrder([
            ['product_id' => $product->id, 'product_variant_id' => $withCost->id, 'quantity' => 1],
            ['product_id' => $product->id, 'product_variant_id' => $withoutCost->id, 'quantity' => 1],
        ]);

        $costs = $order->items()->pluck('unit_cost', 'product_variant_id')->map(fn ($c) => (string) $c)->all();
        $this->assertSame('8.50', $costs[$withCost->id]);
        $this->assertSame('5.00', $costs[$withoutCost->id]);
    }

    public function test_a_line_with_no_known_cost_stores_null(): void
    {
        $product = $this->product('P-NONE', null);

        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]);

        $this->assertNull($order->items()->first()->unit_cost);
    }

    public function test_editing_an_order_records_the_cost_on_the_new_lines(): void
    {
        $product = $this->product('P-EDIT', 3);
        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]);
        $product->update(['purchase_price' => 4]);

        $this->actingAs($this->creator);
        DB::transaction(fn () => app(OrderService::class)->replaceItems($order, [
            ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20],
        ]));

        $this->assertSame('4.00', (string) $order->items()->first()->unit_cost);
    }

    public function test_the_migration_backfills_existing_rows_from_current_cost_and_marks_them(): void
    {
        $product = $this->product('P-OLD', 6);
        $variantProduct = $this->product('P-OLD-VAR', 2, true);
        $variant = $this->variant($variantProduct, 'V-OLD', 9);
        $noCost = $this->product('P-OLD-NONE', null);

        $orderId = DB::table('orders')->insertGetId([
            'organization_id' => $this->org->id, 'order_number' => 'OLD-1', 'status' => 'delivered',
            'subtotal' => 0, 'tax' => 0, 'total' => 0, 'currency' => 'USD', 'order_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $line = fn (?int $productId, ?int $variantId, ?float $unitCost = null) => DB::table('order_items')->insertGetId([
            'order_id' => $orderId, 'product_id' => $productId, 'product_variant_id' => $variantId, 'product_name' => 'Old',
            'quantity' => 1, 'unit_price' => 10, 'subtotal' => 10, 'tax' => 0, 'total' => 10, 'unit_cost' => $unitCost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $plain = $line($product->id, null);
        $viaVariant = $line($variantProduct->id, $variant->id);
        $unknown = $line($noCost->id, null);
        $deletedProduct = $line(null, null);
        $alreadyCosted = $line($product->id, null, 1.5);

        $migration = require database_path('migrations/2026_09_27_100002_add_unit_cost_to_order_items_table.php');
        $migration->backfill();

        $row = fn (int $id) => OrderItem::query()->find($id);

        $this->assertSame('6.00', (string) $row($plain)->unit_cost);
        $this->assertNotNull($row($plain)->unit_cost_backfilled_at);
        $this->assertSame('9.00', (string) $row($viaVariant)->unit_cost);
        $this->assertNotNull($row($viaVariant)->unit_cost_backfilled_at);

        $this->assertNull($row($unknown)->unit_cost);
        $this->assertNull($row($unknown)->unit_cost_backfilled_at);
        $this->assertNull($row($deletedProduct)->unit_cost);

        // Rows that already carry a cost are left alone.
        $this->assertSame('1.50', (string) $row($alreadyCosted)->unit_cost);
        $this->assertNull($row($alreadyCosted)->unit_cost_backfilled_at);
    }
}
