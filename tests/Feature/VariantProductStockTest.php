<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product sold by variant keeps its units on the variants; products.stock
 * stays at whatever it was (usually 0). Every surface used products.stock,
 * so a tee with 5 shirts in stock showed 0, "Out of Stock", a value of $0
 * and a low-stock alert. The stock shown and evaluated for such a product
 * is the sum of its active variants' stock.
 */
class VariantProductStockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Acme', 'email' => 'acme@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $this->tee = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEE',
            'name' => 'Final Tee',
            'price' => 20,
            'purchase_price' => 8,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 2,
            'has_variants' => true,
            'is_active' => true,
        ]);

        // Active: 3 small at the product's price and a cost of 7, 2 medium at
        // 25 and the product's cost. Inactive: 9 large, not counted.
        $this->variant('TEE-S', 3, ['purchase_price' => 7]);
        $this->variant('TEE-M', 2, ['price' => 25]);
        $this->variant('TEE-L', 9, ['is_active' => false]);
    }

    private function variant(string $sku, int $stock, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $this->tee->id,
            'organization_id' => $this->organization->id,
            'sku' => $sku,
            'title' => $sku,
            'option_values' => ['Size' => $sku],
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ], $attributes));
    }

    public function test_the_stock_of_a_variant_product_is_its_active_variants_sum(): void
    {
        $this->assertSame(5, $this->tee->fresh()->total_stock);
        $this->assertSame(5, Product::withEffectiveStock()->findOrFail($this->tee->id)->total_stock);
        $this->assertFalse($this->tee->fresh()->isLowStock());
        $this->assertFalse($this->tee->fresh()->isOutOfStock());
    }

    public function test_low_stock_and_reorder_compare_the_variant_sum(): void
    {
        $this->assertFalse(Product::lowStock()->whereKey($this->tee->id)->exists());

        $this->tee->update(['min_stock' => 6]);
        $this->assertTrue(Product::lowStock()->whereKey($this->tee->id)->exists());

        $this->tee->update(['reorder_point' => 4, 'reorder_quantity' => 10]);
        $this->assertFalse(Product::needsReorder()->whereKey($this->tee->id)->exists());

        $this->tee->update(['reorder_point' => 5]);
        $this->assertTrue(Product::needsReorder()->whereKey($this->tee->id)->exists());
    }

    public function test_the_product_list_shows_the_variant_sum(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.id', $this->tee->id)
                ->where('products.data.0.effective_stock', 5));
    }

    public function test_the_dashboard_does_not_flag_a_stocked_variant_product_and_values_its_variants(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.lowStockProducts', 0)
                ->has('lowStockProducts', 0)
                // 3 x 20 + 2 x 25
                ->where('stats.totalValue', fn ($value) => (float) $value === 110.0));
    }

    public function test_the_low_stock_report_uses_the_variant_sum(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.low-stock'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products', 0));

        $this->tee->update(['min_stock' => 6]);

        $this->actingAs($this->admin)
            ->get(route('reports.low-stock'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.current_stock', 5)
                ->where('products.0.deficit', 1)
                ->where('products.0.status', 'low_stock'));
    }

    public function test_the_valuation_report_values_each_variant(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.inventory-valuation'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.total_quantity', 5)
                ->where('summary.total_stock_value', fn ($v) => (float) $v === 110.0)
                // 3 x 7 + 2 x 8
                ->where('summary.total_cost_value', fn ($v) => (float) $v === 37.0)
                ->where('products.0.stock', 5)
                ->where('products.0.stock_value', fn ($v) => (float) $v === 110.0));
    }

    public function test_category_performance_counts_the_variant_sum(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.category-performance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('categories.0.total_stock', 5)
                ->where('categories.0.low_stock_items', 0));
    }
}
