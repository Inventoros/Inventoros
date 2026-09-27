<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The stock adjustment page can target a product variant (picked by hand or
 * resolved from a scanned variant barcode). A variant adjustment moves the
 * variant's stock through the variant ledger path, never the parent's.
 */
final class StockAdjustmentVariantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $shirt;

    private ProductVariant $small;

    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Shop', 'email' => 'shop@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@shop.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->shirt = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SHIRT', 'name' => 'Shirt', 'price' => 20,
            'currency' => 'USD', 'stock' => 40, 'min_stock' => 0, 'is_active' => true, 'has_variants' => true,
        ]);
        $this->small = $this->variant($this->shirt, 'SHIRT-S', 'Small', 10);

        $this->plain = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'MUG', 'name' => 'Mug', 'price' => 8,
            'currency' => 'USD', 'stock' => 5, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    private function variant(Product $product, string $sku, string $title, int $stock): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'organization_id' => $product->organization_id,
            'sku' => $sku, 'title' => $title, 'option_values' => ['Size' => $title],
            'price' => 20, 'stock' => $stock, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return array_merge([
            'type' => 'damage',
            'adjustment_quantity' => -3,
            'reason' => 'Torn',
        ], $overrides);
    }

    public function test_create_page_lists_each_products_variants(): void
    {
        $this->actingAs($this->admin)
            ->get(route('stock-adjustments.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StockAdjustments/Create')
                ->where('products', function ($products) {
                    $shirt = collect($products)->firstWhere('sku', 'SHIRT');

                    return $shirt['has_variants'] === true
                        && $shirt['variants'][0]['sku'] === 'SHIRT-S'
                        && $shirt['variants'][0]['stock'] === 10;
                })
            );
    }

    public function test_a_variant_adjustment_moves_the_variant_not_the_parent(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $this->payload([
                'product_id' => $this->shirt->id,
                'product_variant_id' => $this->small->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('stock-adjustments.index'));

        $this->assertSame(7, (int) $this->small->fresh()->stock);
        $this->assertSame(40, (int) $this->shirt->fresh()->stock);
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $this->shirt->id,
            'product_variant_id' => $this->small->id,
            'type' => 'damage',
            'adjustment_quantity' => -3,
            'quantity_before' => 10,
            'quantity_after' => 7,
        ]);
    }

    public function test_a_variant_adjustment_cannot_take_the_variant_below_zero(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $this->payload([
                'product_id' => $this->shirt->id,
                'product_variant_id' => $this->small->id,
                'adjustment_quantity' => -11,
            ]))
            ->assertSessionHasErrors('adjustment_quantity');

        $this->assertSame(10, (int) $this->small->fresh()->stock);
    }

    public function test_a_variant_of_another_product_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $this->payload([
                'product_id' => $this->plain->id,
                'product_variant_id' => $this->small->id,
            ]))
            ->assertSessionHasErrors('product_variant_id');

        $this->assertSame(10, (int) $this->small->fresh()->stock);
        $this->assertSame(5, (int) $this->plain->fresh()->stock);
    }

    public function test_a_variant_from_another_organization_is_rejected(): void
    {
        $other = Organization::create([
            'name' => 'Other', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $foreignProduct = Product::create([
            'organization_id' => $other->id, 'sku' => 'F', 'name' => 'Foreign', 'price' => 1,
            'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'has_variants' => true,
        ]);
        $foreign = $this->variant($foreignProduct, 'F-1', 'One', 4);

        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $this->payload([
                'product_id' => $this->shirt->id,
                'product_variant_id' => $foreign->id,
            ]))
            ->assertSessionHasErrors('product_variant_id');

        $this->assertSame(4, (int) $foreign->fresh()->stock);
    }

    public function test_a_variant_product_needs_a_variant(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $this->payload([
                'product_id' => $this->shirt->id,
            ]))
            ->assertSessionHasErrors('product_variant_id');

        $this->assertSame(40, (int) $this->shirt->fresh()->stock);
    }

    public function test_a_location_cannot_be_combined_with_a_variant(): void
    {
        $location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN',
        ]);

        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $this->payload([
                'product_id' => $this->shirt->id,
                'product_variant_id' => $this->small->id,
                'location_id' => $location->id,
            ]))
            ->assertSessionHasErrors('location_id');

        $this->assertSame(10, (int) $this->small->fresh()->stock);
    }

    public function test_a_plain_product_adjustment_is_unchanged(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-adjustments.store'), $this->payload([
                'product_id' => $this->plain->id,
                'adjustment_quantity' => 2,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $this->plain->fresh()->stock);
    }
}
