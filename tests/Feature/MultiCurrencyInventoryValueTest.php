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
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Inventory values are totalled per product currency: the dead stock report
 * and the dashboard's stock-by-category cards never add a CAD value to a USD
 * one. Each headline figure is the organization's currency; the others are
 * listed beside it.
 */
class MultiCurrencyInventoryValueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ProductCategory $tools;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-27 12:00:00');
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Maple', 'email' => 'maple@org.com', 'currency' => 'CAD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@maple.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->tools = ProductCategory::create(['organization_id' => $this->org->id, 'name' => 'Tools', 'slug' => 'tools']);

        // Idle for months: price 20, cost 4 or 5, 10 on hand.
        $this->product('OLD-CAD', 'CAD', 4);
        $this->product('OLD-USD', 'USD', 5);
        $this->product('OLD-EUR', 'eur', 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function product(string $sku, string $currency, float $cost): Product
    {
        $product = Product::create([
            'organization_id' => $this->org->id, 'category_id' => $this->tools->id, 'sku' => $sku, 'name' => $sku,
            'price' => 20, 'purchase_price' => $cost, 'currency' => $currency, 'stock' => 10, 'min_stock' => 0, 'is_active' => true,
        ]);
        $product->forceFill(['created_at' => '2026-01-01 00:00:00'])->saveQuietly();

        return $product;
    }

    public function test_the_dead_stock_summary_is_per_currency(): void
    {
        $result = app(InventoryAnalyticsService::class)->deadStock($this->org->id, 90, 'both', now());

        $this->assertSame(3, $result['summary']['product_count']);
        $this->assertSame(30, $result['summary']['total_units']);
        // The organization's currency only, not 40 + 50 + 10.
        $this->assertSame('CAD', $result['summary']['currency']);
        $this->assertEquals(40, $result['summary']['total_value']);
        $this->assertEquals([
            ['currency' => 'CAD', 'amount' => 40.0],
            ['currency' => 'EUR', 'amount' => 10.0],
            ['currency' => 'USD', 'amount' => 50.0],
        ], $result['summary']['values_by_currency']);

        $this->assertSame(['CAD', 'EUR', 'USD'], collect($result['rows'])->sortBy('currency')->pluck('currency')->values()->all());
    }

    public function test_the_dead_stock_page_and_export_carry_currencies(): void
    {
        $this->actingAs($this->admin)->get(route('reports.dead-stock'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/DeadStock')
                ->where('summary.currency', 'CAD')
                ->where('summary.total_value', 40)
                ->has('summary.values_by_currency', 3)
                ->has('rows.0.currency')
                ->etc()
            );

        $body = $this->actingAs($this->admin)->get(route('reports.dead-stock', ['export' => 'csv']))->streamedContent();

        $this->assertStringContainsString('Currency', $body);
        $this->assertStringContainsString('OLD-USD,OLD-USD,Tools,,10,USD,5,50', $body);
        $this->assertStringContainsString('OLD-CAD,OLD-CAD,Tools,,10,CAD,4,40', $body);
    }

    public function test_the_dashboard_stock_by_category_is_per_currency(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stockByCategory.0.name', 'Tools')
                ->where('stockByCategory.0.count', 3)
                // price x stock in CAD only, not 200 + 200 + 200.
                ->where('stockByCategory.0.value', 200)
                ->where('stockByCategory.0.values', [
                    ['currency' => 'CAD', 'amount' => 200],
                    ['currency' => 'EUR', 'amount' => 200],
                    ['currency' => 'USD', 'amount' => 200],
                ])
                ->where('stats.byCurrency.deadStockValue', [
                    ['currency' => 'CAD', 'amount' => 40],
                    ['currency' => 'EUR', 'amount' => 10],
                    ['currency' => 'USD', 'amount' => 50],
                ])
                ->etc()
            );
    }
}
