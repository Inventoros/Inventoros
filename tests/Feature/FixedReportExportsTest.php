<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The original fixed reports gain server-side CSV / XLSX / PDF export,
 * inventory valuation by location, a previous-period comparison on sales
 * analysis, and bounded SQL aggregates for inventory valuation and category
 * performance.
 */
class FixedReportExportsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->categoryId = DB::table('product_categories')->insertGetId([
            'organization_id' => $this->org->id, 'name' => 'Tools', 'slug' => 'tools', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = DB::table('product_locations')->insertGetId([
            'organization_id' => $this->org->id, 'name' => 'Shelf A', 'code' => 'SA', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $a = $this->product('=cmd|calc', 'VAL-A', 10, 5, 2, $this->categoryId, $locationId, 1);
        $this->product('Plain', 'VAL-B', 4, 10, 6, null, null, 20);
        DB::table('product_location_stocks')->insert([
            'organization_id' => $this->org->id, 'product_id' => $a, 'location_id' => $locationId,
            'quantity' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Another tenant's product must not reach any figure.
        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        DB::table('products')->insert([
            'organization_id' => $other->id, 'sku' => 'THEIRS', 'name' => 'Theirs', 'price' => 999, 'purchase_price' => 999,
            'currency' => 'USD', 'stock' => 999, 'min_stock' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(string $name, string $sku, int $stock, float $price, float $cost, ?int $categoryId, ?int $locationId, int $minStock): int
    {
        return DB::table('products')->insertGetId([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => $name, 'price' => $price, 'purchase_price' => $cost,
            'currency' => 'USD', 'stock' => $stock, 'min_stock' => $minStock, 'is_active' => true,
            'category_id' => $categoryId, 'location_id' => $locationId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(string $number, string $date, float $total, int $qty): void
    {
        $id = DB::table('orders')->insertGetId([
            'organization_id' => $this->org->id, 'order_number' => $number, 'status' => 'delivered',
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'currency' => 'USD',
            'order_date' => $date, 'created_at' => $date, 'updated_at' => $date,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'product_id' => null, 'product_name' => 'Line', 'sku' => 'L', 'quantity' => $qty,
            'unit_price' => $total / $qty, 'subtotal' => $total, 'tax' => 0, 'total' => $total, 'created_at' => $date, 'updated_at' => $date,
        ]);
    }

    public function test_inventory_valuation_figures_are_sql_aggregates_scoped_to_the_org(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.inventory-valuation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_items', 2)
                ->where('summary.total_quantity', 14)
                ->where('summary.total_stock_value', 90)      // 10x5 + 4x10
                ->where('summary.total_cost_value', 44)       // 10x2 + 4x6
                ->where('summary.total_profit_potential', 46)
                ->has('products', 2)
                ->where('truncated', false)
                ->has('byCategory', 2)
                ->has('byLocation', 2) // Shelf A + unallocated (VAL-B)
            );
    }

    public function test_inventory_valuation_list_is_capped_but_totals_are_not(): void
    {
        config(['reports.max_rows' => 1]);

        $this->actingAs($this->admin)
            ->get(route('reports.inventory-valuation'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 1)
                ->where('summary.total_items', 2)
                ->where('truncated', true)
            );
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function exportRoutes(): array
    {
        return [
            'valuation' => ['reports.inventory-valuation', []],
            'valuation by category' => ['reports.inventory-valuation', ['group' => 'category']],
            'valuation by location' => ['reports.inventory-valuation', ['group' => 'location']],
            'stock movement' => ['reports.stock-movement', []],
            'sales analysis' => ['reports.sales-analysis', []],
            'sales by status' => ['reports.sales-analysis', ['group' => 'status']],
            'low stock' => ['reports.low-stock', []],
            'category performance' => ['reports.category-performance', []],
        ];
    }

    /** @param array<string, string> $params */
    #[DataProvider('exportRoutes')]
    public function test_fixed_reports_export_in_every_format(string $route, array $params): void
    {
        foreach ([
            'csv' => 'text/csv; charset=UTF-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
        ] as $format => $mime) {
            $response = $this->actingAs($this->admin)->get(route($route, $params + ['export' => $format]));

            $response->assertOk();
            $this->assertSame($mime, $response->headers->get('Content-Type'), "{$route} {$format}");
            $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        }
    }

    public function test_valuation_csv_is_neutralised_and_scoped(): void
    {
        $body = $this->actingAs($this->admin)
            ->get(route('reports.inventory-valuation', ['export' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $body);
        $this->assertStringNotContainsString('THEIRS', $body);
    }

    public function test_low_stock_csv_lists_the_low_stock_rows(): void
    {
        $body = $this->actingAs($this->admin)
            ->get(route('reports.low-stock', ['export' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString('VAL-B', $body);   // stock 4 <= min 20
        $this->assertStringNotContainsString('VAL-A', $body); // stock 10 > min 1
    }

    public function test_sales_analysis_compares_with_the_previous_period(): void
    {
        $this->order('CUR-1', '2026-06-15 10:00:00', 150, 3);
        $this->order('PREV-1', '2026-06-05 10:00:00', 100, 1);

        $this->actingAs($this->admin)
            ->get(route('reports.sales-analysis', ['date_from' => '2026-06-11', 'date_to' => '2026-06-20']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_revenue', 150)
                ->where('comparison.previousPeriod.date_from', '2026-06-01')
                ->where('comparison.previousPeriod.date_to', '2026-06-10')
                ->where('comparison.previous.total_revenue', 100)
                ->where('comparison.delta.total_revenue', 50)
                ->where('comparison.delta.total_items_sold', 200)
            );
    }

    public function test_category_performance_matches_the_catalogue(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.category-performance'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_categories', 2)
                ->where('summary.total_products', 2)
                ->where('summary.total_value', 90)
                ->where('categories.0.category_name', 'Tools')
                ->where('categories.0.total_value', 50)
                ->where('categories.1.category_name', 'Uncategorized')
                ->where('categories.1.low_stock_items', 1)
            );
    }
}
