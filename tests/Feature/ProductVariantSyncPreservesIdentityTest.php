<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\OrderItem;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A product update that sends its variants without ids (the REST, GraphQL
 * and MCP shape) used to soft-delete every existing variant and create new
 * rows. Order, purchase order and return lines point at the old ids, and a
 * variant's stock lives on its row, so the "same" variant came back with a
 * new id and zero history. The sync now matches id-less variants to existing
 * ones by SKU, then by option combination, updates them in place, and only
 * creates genuinely new ones. A variant dropped from the payload is
 * deactivated when it has stock or is referenced, and soft-deleted otherwise.
 */
final class ProductVariantSyncPreservesIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    private ProductVariant $small;

    private ProductVariant $medium;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();

        $this->org = Organization::create([
            'name' => 'Sync Org', 'email' => 'sync@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@sync.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'TEE', 'name' => 'Tee',
            'price' => 10, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0,
            'is_active' => true, 'has_variants' => true,
        ]);
        $this->small = $this->variant('TEE-S', ['Size' => 'S'], 10, 0);
        $this->medium = $this->variant('TEE-M', ['Size' => 'M'], 6, 1);

        Sanctum::actingAs($this->admin);
        $this->actingAs($this->admin);
    }

    private function variant(string $sku, array $options, int $stock, int $position): ProductVariant
    {
        return ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $this->product->id,
            'sku' => $sku, 'title' => implode(' / ', $options), 'option_values' => $options,
            'price' => 10, 'stock' => $stock, 'min_stock' => 0, 'is_active' => true, 'position' => $position,
        ]);
    }

    private function sellSmall(int $qty): OrderItem
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme',
            'items' => [['product_id' => $this->product->id, 'product_variant_id' => $this->small->id, 'quantity' => $qty, 'unit_price' => 10]],
        ], $this->admin);

        return $order->items()->sole();
    }

    public function test_variants_sent_without_ids_are_matched_by_sku_and_keep_their_ids_stock_and_order_lines(): void
    {
        $line = $this->sellSmall(2); // S: 10 -> 8

        $this->putJson("/api/v1/products/{$this->product->id}", [
            'variants' => [
                ['option_values' => ['Size' => 'S'], 'sku' => 'TEE-S', 'price' => 12],
                ['option_values' => ['Size' => 'M'], 'sku' => 'TEE-M', 'price' => 13],
            ],
        ])->assertOk();

        $this->assertSame(2, ProductVariant::withTrashed()->where('product_id', $this->product->id)->count(), 'No variant is recreated.');
        $small = ProductVariant::find($this->small->id);
        $this->assertNotNull($small);
        $this->assertSame(8, (int) $small->stock);
        $this->assertSame('12.00', number_format((float) $small->price, 2, '.', ''));
        $this->assertSame(6, (int) ProductVariant::find($this->medium->id)->stock);
        $this->assertSame($this->small->id, (int) $line->fresh()->product_variant_id);
        $this->assertSame(0, StockAdjustment::where('type', 'opening_stock')->count());
    }

    public function test_variants_without_ids_or_matching_skus_are_matched_by_option_combination(): void
    {
        $this->putJson("/api/v1/products/{$this->product->id}", [
            'variants' => [
                ['option_values' => ['Size' => 'S'], 'sku' => 'TEE-SMALL'],
                ['option_values' => ['Size' => 'M']],
            ],
        ])->assertOk();

        $small = ProductVariant::find($this->small->id);
        $this->assertSame('TEE-SMALL', $small->sku);
        $this->assertSame(10, (int) $small->stock);
        $this->assertNotNull(ProductVariant::find($this->medium->id));
        $this->assertSame(2, ProductVariant::withTrashed()->where('product_id', $this->product->id)->count());
    }

    public function test_a_genuinely_new_variant_is_created_with_an_opening_stock_row(): void
    {
        $this->putJson("/api/v1/products/{$this->product->id}", [
            'variants' => [
                ['option_values' => ['Size' => 'S'], 'sku' => 'TEE-S'],
                ['option_values' => ['Size' => 'M'], 'sku' => 'TEE-M'],
                ['option_values' => ['Size' => 'L'], 'sku' => 'TEE-L', 'stock' => 4],
            ],
        ])->assertOk();

        $large = ProductVariant::where('sku', 'TEE-L')->sole();
        $this->assertSame(4, (int) $large->stock);
        $this->assertSame('opening_stock', StockAdjustment::where('product_variant_id', $large->id)->sole()->type);
    }

    public function test_stock_on_a_row_matched_to_an_existing_variant_is_ignored(): void
    {
        $this->putJson("/api/v1/products/{$this->product->id}", [
            'variants' => [
                ['option_values' => ['Size' => 'S'], 'sku' => 'TEE-S', 'stock' => 99],
                ['option_values' => ['Size' => 'M'], 'sku' => 'TEE-M'],
            ],
        ])->assertOk();

        $this->assertSame(10, (int) ProductVariant::find($this->small->id)->stock);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_a_dropped_variant_with_stock_or_references_is_deactivated_not_deleted(): void
    {
        $this->sellSmall(1);
        $empty = $this->variant('TEE-XL', ['Size' => 'XL'], 0, 2);

        // Only M is sent: S has stock and an order line, XL has neither.
        $this->putJson("/api/v1/products/{$this->product->id}", [
            'variants' => [['option_values' => ['Size' => 'M'], 'sku' => 'TEE-M']],
        ])->assertOk();

        $small = ProductVariant::withTrashed()->find($this->small->id);
        $this->assertNull($small->deleted_at);
        $this->assertFalse((bool) $small->is_active);
        $this->assertSame(9, (int) $small->stock);

        $this->assertNotNull(ProductVariant::withTrashed()->find($empty->id)->deleted_at);
    }

    public function test_disabling_variants_keeps_stocked_or_referenced_variants(): void
    {
        $this->sellSmall(1);

        $this->putJson("/api/v1/products/{$this->product->id}", ['has_variants' => false])->assertOk();

        $small = ProductVariant::withTrashed()->find($this->small->id);
        $this->assertNull($small->deleted_at);
        $this->assertFalse((bool) $small->is_active);
    }

    public function test_graphql_product_update_leaves_variants_untouched(): void
    {
        $this->postJson('/graphql', ['query' => sprintf(
            'mutation { updateProduct(id: %d, name: "Tee 2") { id name } }', $this->product->id
        )])->assertJsonMissingPath('errors');

        // GraphQL has no variants argument today; the product edit must leave
        // the variants alone.
        $this->assertSame(10, (int) ProductVariant::find($this->small->id)->stock);
        $this->assertTrue((bool) ProductVariant::find($this->medium->id)->is_active);
    }

    // ==================== REST variant destroy ====================

    public function test_destroying_a_variant_that_holds_stock_is_refused(): void
    {
        $response = $this->deleteJson("/api/v1/products/{$this->product->id}/variants/{$this->medium->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'variant_in_use');
        $this->assertStringContainsString('is_active', $response->json('message'));

        $this->assertNull(ProductVariant::withTrashed()->find($this->medium->id)->deleted_at);
    }

    public function test_destroying_a_variant_referenced_by_an_order_line_is_refused(): void
    {
        $line = $this->sellSmall(10); // S: 10 -> 0, but the order line points at it
        $this->assertSame(0, (int) $this->small->fresh()->stock);

        $this->deleteJson("/api/v1/products/{$this->product->id}/variants/{$this->small->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'variant_in_use');

        $this->assertNull(ProductVariant::withTrashed()->find($this->small->id)->deleted_at);
        $this->assertSame($this->small->id, (int) $line->fresh()->product_variant_id);
    }

    public function test_an_unused_empty_variant_can_still_be_destroyed(): void
    {
        $empty = $this->variant('TEE-XL', ['Size' => 'XL'], 0, 2);

        $this->deleteJson("/api/v1/products/{$this->product->id}/variants/{$empty->id}")->assertOk();

        $this->assertNotNull(ProductVariant::withTrashed()->find($empty->id)->deleted_at);
    }
}
