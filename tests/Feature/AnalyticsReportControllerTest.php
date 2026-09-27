<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The analytics report pages: view_reports plus the per-source permission
 * for the data each one reads (the same rule the report builder applies),
 * organization scoping, and CSV / XLSX / PDF export from the page route.
 */
class AnalyticsReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->otherOrg = Organization::create(['name' => 'Other', 'email' => 'x@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->seedData($this->org, 'MINE');
        $this->seedData($this->otherOrg, 'THEIRS');
    }

    private function seedData(Organization $org, string $prefix): void
    {
        $productId = DB::table('products')->insertGetId([
            'organization_id' => $org->id, 'sku' => "{$prefix}-1", 'name' => "{$prefix} product",
            'price' => 10, 'purchase_price' => 4, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'is_active' => true, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);
        $idle = DB::table('products')->insertGetId([
            'organization_id' => $org->id, 'sku' => "{$prefix}-IDLE", 'name' => "=HYPERLINK(\"{$prefix}\")",
            'price' => 10, 'purchase_price' => 2, 'currency' => 'USD', 'stock' => 7, 'min_stock' => 0,
            'is_active' => true, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'organization_id' => $org->id, 'order_number' => "{$prefix}-ORD", 'status' => 'delivered',
            'subtotal' => 20, 'tax' => 0, 'total' => 20, 'currency' => 'USD',
            'order_date' => now()->subDays(3), 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'product_name' => "{$prefix} product", 'sku' => "{$prefix}-1",
            'quantity' => 2, 'unit_price' => 10, 'subtotal' => 20, 'tax' => 0, 'total' => 20,
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);
        unset($idle);
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions, ?Organization $org = null): User
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'name' => "User {$n}", 'email' => "user{$n}@test.com", 'password' => bcrypt('x'),
            'organization_id' => ($org ?? $this->org)->id, 'role' => 'member',
        ]);
        $role = Role::create(['name' => "Role {$n}", 'slug' => "role-{$n}", 'is_system' => false, 'permissions' => $permissions]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function analyst(): User
    {
        return $this->userWith(['view_reports', 'view_products', 'view_orders']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function reportRoutes(): array
    {
        return [
            'dead stock' => ['reports.dead-stock', 'Reports/DeadStock'],
            'turnover' => ['reports.inventory-turnover', 'Reports/InventoryTurnover'],
            'profit margin' => ['reports.profit-margin', 'Reports/ProfitMargin'],
            'sales by location' => ['reports.sales-by-location', 'Reports/SalesByLocation'],
            'abc' => ['reports.abc-analysis', 'Reports/AbcAnalysis'],
        ];
    }

    #[DataProvider('reportRoutes')]
    public function test_each_report_renders_for_an_analyst(string $route, string $component): void
    {
        $this->actingAs($this->analyst())
            ->get(route($route))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component)->has('filters'));
    }

    #[DataProvider('reportRoutes')]
    public function test_each_report_requires_view_reports(string $route): void
    {
        $this->actingAs($this->userWith(['view_products', 'view_orders']))
            ->get(route($route))
            ->assertForbidden();
    }

    /** @return array<string, array{0: string, 1: array<int, string>}> */
    public static function sourcePermissionGaps(): array
    {
        return [
            'dead stock without products' => ['reports.dead-stock', ['view_reports', 'view_orders']],
            'dead stock without orders' => ['reports.dead-stock', ['view_reports', 'view_products']],
            'turnover without orders' => ['reports.inventory-turnover', ['view_reports', 'view_products']],
            'margin without products' => ['reports.profit-margin', ['view_reports', 'view_orders']],
            'sales by location without orders' => ['reports.sales-by-location', ['view_reports', 'view_products']],
            'abc without orders' => ['reports.abc-analysis', ['view_reports', 'view_products']],
        ];
    }

    /** @param array<int, string> $permissions */
    #[DataProvider('sourcePermissionGaps')]
    public function test_each_report_requires_its_source_permissions(string $route, array $permissions): void
    {
        $this->actingAs($this->userWith($permissions))
            ->get(route($route))
            ->assertForbidden();
    }

    public function test_dead_stock_is_scoped_to_the_viewers_organization(): void
    {
        $this->actingAs($this->analyst())
            ->get(route('reports.dead-stock', ['days' => 30]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.sku', 'MINE-IDLE')
                ->where('filters.days', 30)
                ->where('filters.basis', 'both')
            );
    }

    public function test_dead_stock_rejects_an_unsupported_window_by_falling_back_to_90_days(): void
    {
        $this->actingAs($this->analyst())
            ->get(route('reports.dead-stock', ['days' => 7, 'basis' => 'bogus']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.days', 90)
                ->where('filters.basis', 'both')
            );
    }

    public function test_profit_margin_page_states_the_cost_basis(): void
    {
        $this->actingAs($this->analyst())
            ->get(route('reports.profit-margin'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('costBasis', 'current_purchase_price')
                ->where('summary.revenue', 20)
                ->where('summary.cogs', 8)
            );
    }

    public function test_turnover_page_carries_its_period(): void
    {
        $this->actingAs($this->analyst())
            ->get(route('reports.inventory-turnover', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.date_from', '2026-01-01')
                ->where('summary.period_days', 31)
                ->has('products')
                ->has('categories')
            );
    }

    public function test_csv_export_is_neutralised_and_scoped(): void
    {
        $response = $this->actingAs($this->analyst())
            ->get(route('reports.dead-stock', ['days' => 30, 'export' => 'csv']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringNotContainsString('THEIRS', $body);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function exportFormats(): array
    {
        return [
            'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pdf' => ['pdf', 'application/pdf'],
        ];
    }

    #[DataProvider('exportFormats')]
    public function test_every_analytics_report_exports_in_each_format(string $format, string $mime): void
    {
        $user = $this->analyst();

        foreach (array_keys(self::reportRoutes()) as $key) {
            [$route] = self::reportRoutes()[$key];
            $response = $this->actingAs($user)->get(route($route, ['export' => $format]));

            $response->assertOk();
            $this->assertSame($mime, $response->headers->get('Content-Type'), $route);
        }
    }

    public function test_category_and_location_groupings_export(): void
    {
        $user = $this->analyst();

        foreach ([
            ['reports.inventory-turnover', 'category', 'Avg inventory value'],
            ['reports.profit-margin', 'category', 'Margin %'],
            ['reports.sales-by-location', 'location', 'Location'],
        ] as [$route, $group, $expectedHeader]) {
            $body = $this->actingAs($user)->get(route($route, ['export' => 'csv', 'group' => $group]))->streamedContent();
            $this->assertStringContainsString($expectedHeader, $body, $route);
        }
    }

    public function test_the_export_is_blocked_by_the_same_permission_gate(): void
    {
        $this->actingAs($this->userWith(['view_reports', 'view_orders']))
            ->get(route('reports.profit-margin', ['export' => 'csv']))
            ->assertForbidden();
    }
}
