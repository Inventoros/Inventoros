<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Reports\InventoryAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The inventory valuation and category performance reports, and the
 * dashboard's top products, never add product values in different
 * currencies together. Each headline figure is in the organization's
 * currency; every other currency is listed beside it, and exports carry one
 * row per currency.
 */
class MultiCurrencyValuationReportsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Maple', 'email' => 'maple@org.com', 'currency' => 'CAD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@maple.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $tools = ProductCategory::create(['organization_id' => $this->org->id, 'name' => 'Tools', 'slug' => 'tools']);
        $parts = ProductCategory::create(['organization_id' => $this->org->id, 'name' => 'Parts', 'slug' => 'parts']);

        // stock x price / stock x cost:
        //   HAMMER  CAD  3 x 10 = 30 /  3 x 4 = 12   (Tools)
        //   DRILL   USD  2 x 20 = 40 /  2 x 5 = 10   (Tools)
        //   BOLT    EUR  4 x  5 = 20 /  4 x 1 =  4   (Parts)
        $this->product('HAMMER', 'CAD', 10, 4, 3, $tools->id);
        $this->product('DRILL', 'USD', 20, 5, 2, $tools->id);
        $this->product('BOLT', 'eur', 5, 1, 4, $parts->id);
    }

    private function product(string $sku, string $currency, float $price, float $cost, int $stock, int $categoryId): void
    {
        DB::table('products')->insert([
            'organization_id' => $this->org->id, 'category_id' => $categoryId, 'sku' => $sku, 'name' => $sku,
            'price' => $price, 'purchase_price' => $cost, 'currency' => $currency, 'stock' => $stock,
            'min_stock' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_inventory_valuation_is_per_currency(): void
    {
        $this->actingAs($this->admin)->get(route('reports.inventory-valuation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/InventoryValuation')
                ->where('summary.total_items', 3)
                ->where('summary.total_quantity', 9)
                // CAD only, not 30 + 40 + 20.
                ->where('summary.currency', 'CAD')
                ->where('summary.total_stock_value', 30)
                ->where('summary.total_cost_value', 12)
                ->where('summary.total_profit_potential', 18)
                ->where('summary.by_currency', [
                    ['currency' => 'CAD', 'items' => 1, 'quantity' => 3, 'stock_value' => 30, 'cost_value' => 12, 'profit_potential' => 18],
                    ['currency' => 'EUR', 'items' => 1, 'quantity' => 4, 'stock_value' => 20, 'cost_value' => 4, 'profit_potential' => 16],
                    ['currency' => 'USD', 'items' => 1, 'quantity' => 2, 'stock_value' => 40, 'cost_value' => 10, 'profit_potential' => 30],
                ])
                ->where('products.0.sku', 'DRILL')
                ->where('products.0.currency', 'USD')
                ->where('byCategory.0.category', 'Tools')
                ->where('byCategory.0.items', 2)
                ->where('byCategory.0.value', 30)
                ->where('byCategory.0.values', [['currency' => 'CAD', 'amount' => 30], ['currency' => 'USD', 'amount' => 40]])
                ->where('byCategory.1.category', 'Parts')
                ->where('byCategory.1.value', 0)
                ->where('byCategory.1.values', [['currency' => 'CAD', 'amount' => 0], ['currency' => 'EUR', 'amount' => 20]])
                ->etc()
            );
    }

    public function test_valuation_by_location_is_per_currency(): void
    {
        $rows = app(InventoryAnalyticsService::class)->valuationByLocation($this->org->id);

        // Everything is unallocated here.
        $this->assertCount(1, $rows);
        $this->assertSame('CAD', $rows[0]['currency']);
        $this->assertSame(9, $rows[0]['quantity']);
        $this->assertSame(12.0, $rows[0]['cost_value']);
        $this->assertSame(30.0, $rows[0]['retail_value']);
        $this->assertSame([
            ['currency' => 'CAD', 'products' => 1, 'quantity' => 3, 'cost_value' => 12.0, 'retail_value' => 30.0],
            ['currency' => 'EUR', 'products' => 1, 'quantity' => 4, 'cost_value' => 4.0, 'retail_value' => 20.0],
            ['currency' => 'USD', 'products' => 1, 'quantity' => 2, 'cost_value' => 10.0, 'retail_value' => 40.0],
        ], $rows[0]['values']);
    }

    public function test_valuation_exports_carry_the_currency(): void
    {
        $this->actingAs($this->admin);

        $products = $this->get(route('reports.inventory-valuation', ['export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Currency', $products);
        $this->assertStringContainsString('DRILL,DRILL,Tools,,2,USD,20', $products);

        $categories = $this->get(route('reports.inventory-valuation', ['export' => 'csv', 'group' => 'category']))->streamedContent();
        $this->assertStringContainsString('Tools,1,3,CAD,30', $categories);
        $this->assertStringContainsString('Tools,1,2,USD,40', $categories);
        $this->assertStringContainsString('Parts,1,4,EUR,20', $categories);

        $locations = $this->get(route('reports.inventory-valuation', ['export' => 'csv', 'group' => 'location']))->streamedContent();
        $this->assertStringContainsString('Unallocated,,1,4,EUR,4,20', $locations);
    }

    public function test_category_performance_is_per_currency(): void
    {
        $this->actingAs($this->admin)->get(route('reports.category-performance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/CategoryPerformance')
                ->where('summary.total_categories', 2)
                ->where('summary.total_products', 3)
                ->where('summary.currency', 'CAD')
                ->where('summary.total_value', 30)
                ->where('summary.values_by_currency', [
                    ['currency' => 'CAD', 'amount' => 30],
                    ['currency' => 'EUR', 'amount' => 20],
                    ['currency' => 'USD', 'amount' => 40],
                ])
                ->where('categories.0.category_name', 'Tools')
                ->where('categories.0.product_count', 2)
                ->where('categories.0.total_stock', 5)
                ->where('categories.0.total_value', 30)
                // Average over the CAD products only: 30 / 1.
                ->where('categories.0.average_value', 30)
                ->where('categories.0.values', [['currency' => 'CAD', 'amount' => 30], ['currency' => 'USD', 'amount' => 40]])
                ->where('categories.1.category_name', 'Parts')
                ->where('categories.1.total_value', 0)
                ->where('categories.1.average_value', null)
                ->etc()
            );

        $csv = $this->get(route('reports.category-performance', ['export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Currency', $csv);
        $this->assertStringContainsString('Tools,1,3,CAD,30,0', $csv);
        $this->assertStringContainsString('Tools,1,2,USD,40,0', $csv);
    }

    public function test_dashboard_top_products_carry_their_currency_and_rank_the_orgs_first(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                // HAMMER (CAD 30) leads even though DRILL's USD 40 is a
                // bigger number: values in different currencies don't rank
                // against each other.
                ->where('topProducts.0.sku', 'HAMMER')
                ->where('topProducts.0.currency', 'CAD')
                ->where('topProducts.1.currency', 'EUR')
                ->where('topProducts.2.currency', 'USD')
                ->etc()
            );
    }
}
