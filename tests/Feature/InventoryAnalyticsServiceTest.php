<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Services\Reports\InventoryAnalyticsService;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The analytics reports aggregate in SQL over order_items, orders,
 * stock_adjustments and product_location_stocks. These tests pin the
 * arithmetic, the org scoping and the row cap on SQLite; the queries use no
 * SQLite-only functions so they run unchanged on MySQL and Postgres.
 */
class InventoryAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryAnalyticsService $service;

    private CarbonImmutable $now;

    private int $orgId;

    private int $otherOrgId;

    private int $userId;

    private int $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-06-30 12:00:00');
        CarbonImmutable::setTestNow($this->now);
        Carbon::setTestNow($this->now);

        $this->service = app(InventoryAnalyticsService::class);

        [$this->orgId, $this->userId] = $this->makeOrg('a');
        [$this->otherOrgId, $this->otherUserId] = $this->makeOrg('b');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: int, 1: int} */
    private function makeOrg(string $slug): array
    {
        $org = Organization::create([
            'name' => "Org {$slug}", 'email' => "{$slug}@org.test", 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $user = User::create([
            'name' => "U {$slug}", 'email' => "u-{$slug}@org.test", 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);

        return [$org->id, $user->id];
    }

    private function category(string $name, ?int $orgId = null): int
    {
        return DB::table('product_categories')->insertGetId([
            'organization_id' => $orgId ?? $this->orgId, 'name' => $name, 'slug' => strtolower($name),
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    private function warehouse(string $name, ?int $orgId = null): int
    {
        return DB::table('warehouses')->insertGetId([
            'organization_id' => $orgId ?? $this->orgId, 'name' => $name, 'code' => strtoupper($name),
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    private function location(string $name, ?int $warehouseId = null, ?int $orgId = null): int
    {
        return DB::table('product_locations')->insertGetId([
            'organization_id' => $orgId ?? $this->orgId, 'warehouse_id' => $warehouseId, 'name' => $name,
            'code' => strtoupper($name), 'is_active' => true,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function product(string $sku, array $attrs = []): int
    {
        $created = $attrs['created_at'] ?? $this->now->subDays(365);

        return DB::table('products')->insertGetId(array_merge([
            'organization_id' => $this->orgId, 'sku' => $sku, 'name' => "Product {$sku}",
            'price' => 10, 'purchase_price' => 4, 'currency' => 'USD', 'stock' => 10, 'min_stock' => 0,
            'is_active' => true, 'created_at' => $created, 'updated_at' => $created,
        ], $attrs));
    }

    /**
     * @param  array<int, array{0: int|null, 1: int, 2: float}>  $lines  [product_id, qty, unit price]
     */
    private function order(CarbonImmutable $date, array $lines, string $status = 'delivered', ?int $orgId = null, ?int $warehouseId = null): int
    {
        static $n = 0;
        $n++;
        $subtotal = array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines));
        $orderId = DB::table('orders')->insertGetId([
            'organization_id' => $orgId ?? $this->orgId, 'warehouse_id' => $warehouseId,
            'order_number' => "ORD-{$n}", 'status' => $status,
            'subtotal' => $subtotal, 'tax' => 0, 'total' => $subtotal * 1.1, 'currency' => 'USD',
            'order_date' => $date, 'created_at' => $date, 'updated_at' => $date,
        ]);
        foreach ($lines as [$productId, $qty, $price]) {
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'product_id' => $productId, 'product_name' => "Line {$productId}",
                'sku' => "SKU-{$productId}", 'quantity' => $qty, 'unit_price' => $price,
                'subtotal' => $qty * $price, 'tax' => 0, 'total' => $qty * $price * 1.1,
                'created_at' => $date, 'updated_at' => $date,
            ]);
        }

        return $orderId;
    }

    private function adjustment(int $productId, int $qty, CarbonImmutable $at, ?int $orgId = null): void
    {
        DB::table('stock_adjustments')->insert([
            'organization_id' => $orgId ?? $this->orgId, 'product_id' => $productId,
            'user_id' => $orgId === $this->otherOrgId ? $this->otherUserId : $this->userId,
            'type' => $qty < 0 ? 'decrease' : 'increase', 'quantity_before' => 0, 'quantity_after' => 0,
            'adjustment_quantity' => $qty, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    // ---------------------------------------------------------------- dead stock

    public function test_dead_stock_lists_idle_products_with_their_tied_up_value(): void
    {
        $fast = $this->product('FAST', ['stock' => 10, 'purchase_price' => 5]);
        $dead = $this->product('DEAD', ['stock' => 20, 'purchase_price' => 3]);
        $never = $this->product('NEVER', ['stock' => 4, 'purchase_price' => null]);
        $this->product('NEW', ['stock' => 5, 'created_at' => $this->now->subDays(5)]);
        $this->product('EMPTY', ['stock' => 0]);

        $this->order($this->now->subDays(10), [[$fast, 1, 10]]);
        $this->order($this->now->subDays(200), [[$dead, 2, 8]]);
        // A cancelled order is not a sale.
        $this->order($this->now->subDays(3), [[$dead, 1, 8]], 'cancelled');

        // Another tenant's idle stock must never appear.
        $this->product('B-IDLE', ['organization_id' => $this->otherOrgId, 'stock' => 99]);

        $result = $this->service->deadStock($this->orgId, 90, 'both', $this->now);

        $skus = array_column($result['rows'], 'sku');
        $this->assertSame(['DEAD', 'NEVER'], $skus); // ordered by tied-up value desc
        $this->assertSame(60.0, $result['rows'][0]['tied_up_value']);
        $this->assertStringStartsWith($this->now->subDays(200)->format('Y-m-d'), (string) $result['rows'][0]['last_sale_at']);
        $this->assertNull($result['rows'][1]['last_sale_at']);
        $this->assertSame(0.0, $result['rows'][1]['tied_up_value']);
        $this->assertTrue($result['rows'][1]['cost_missing']);

        $this->assertSame(2, $result['summary']['product_count']);
        $this->assertSame(24, $result['summary']['total_units']);
        $this->assertSame(60.0, $result['summary']['total_value']);
        $this->assertFalse($result['truncated']);
    }

    public function test_dead_stock_basis_selects_sales_or_movement(): void
    {
        $p = $this->product('MOVED', ['stock' => 10]);
        // No sales at all, but an outbound adjustment 5 days ago.
        $this->adjustment($p, -2, $this->now->subDays(5));

        $bySales = $this->service->deadStock($this->orgId, 30, 'sales', $this->now);
        $byMovement = $this->service->deadStock($this->orgId, 30, 'movement', $this->now);
        $both = $this->service->deadStock($this->orgId, 30, 'both', $this->now);

        $this->assertSame(['MOVED'], array_column($bySales['rows'], 'sku'));
        $this->assertSame([], $byMovement['rows']);
        $this->assertSame([], $both['rows']);
    }

    public function test_dead_stock_is_capped_at_max_rows_but_totals_are_not(): void
    {
        config(['reports.max_rows' => 2]);
        foreach (['A', 'B', 'C'] as $sku) {
            $this->product($sku, ['stock' => 1, 'purchase_price' => 1]);
        }

        $result = $this->service->deadStock($this->orgId, 30, 'both', $this->now);

        $this->assertCount(2, $result['rows']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(3, $result['summary']['product_count']);
    }

    // ----------------------------------------------------------------- turnover

    public function test_turnover_reconstructs_opening_and_closing_stock_from_adjustments(): void
    {
        $cat = $this->category('Tools');
        $p = $this->product('TURN', ['stock' => 30, 'purchase_price' => 2, 'category_id' => $cat]);
        $period = ReportPeriod::fromDates('2026-06-01', '2026-06-10');

        // Within the period: -20 (sale) and +10 (receipt) => net -10.
        $this->adjustment($p, -20, CarbonImmutable::parse('2026-06-05 10:00'));
        $this->adjustment($p, 10, CarbonImmutable::parse('2026-06-06 10:00'));
        // After the period: +20.
        $this->adjustment($p, 20, CarbonImmutable::parse('2026-06-20 10:00'));
        $this->order(CarbonImmutable::parse('2026-06-05 10:00'), [[$p, 20, 5]]);

        // Another org's sale of a same-named SKU must not leak in.
        $bProduct = $this->product('TURN-B', ['organization_id' => $this->otherOrgId]);
        $this->order(CarbonImmutable::parse('2026-06-05 10:00'), [[$bProduct, 50, 5]], 'delivered', $this->otherOrgId);

        $result = $this->service->inventoryTurnover($this->orgId, $period);

        $row = collect($result['products'])->firstWhere('sku', 'TURN');
        // closing = 30 - 20 = 10 ; opening = 10 - (-10) = 20 ; average = 15 units
        $this->assertSame(20.0, $row['opening_units']);
        $this->assertSame(10.0, $row['closing_units']);
        $this->assertSame(30.0, $row['average_value']); // 15 units x cost 2
        $this->assertSame(40.0, $row['cogs']);          // 20 sold x cost 2
        $this->assertSame(1.33, $row['turnover']);
        $this->assertSame(7.5, $row['days_of_inventory']); // 10 days / 1.333

        $this->assertNull(collect($result['products'])->firstWhere('sku', 'TURN-B'));

        $category = collect($result['categories'])->firstWhere('category', 'Tools');
        $this->assertSame(40.0, $category['cogs']);
        $this->assertSame(30.0, $category['average_value']);
        $this->assertSame(1.33, $category['turnover']);

        $this->assertSame(40.0, $result['summary']['cogs']);
        $this->assertSame(10, $result['summary']['period_days']);
    }

    public function test_turnover_is_null_when_there_is_no_average_inventory(): void
    {
        $p = $this->product('ZERO', ['stock' => 0, 'purchase_price' => 2]);
        $this->order(CarbonImmutable::parse('2026-06-05 10:00'), [[$p, 1, 5]]);

        $result = $this->service->inventoryTurnover($this->orgId, ReportPeriod::fromDates('2026-06-01', '2026-06-10'));

        $row = collect($result['products'])->firstWhere('sku', 'ZERO');
        $this->assertNull($row['turnover']);
        $this->assertNull($row['days_of_inventory']);
    }

    // ------------------------------------------------------------ profit margin

    public function test_profit_margin_uses_current_cost_and_flags_missing_cost(): void
    {
        $cat = $this->category('Gadgets');
        $a = $this->product('MA', ['purchase_price' => 6, 'category_id' => $cat]);
        $b = $this->product('MB', ['purchase_price' => null, 'category_id' => $cat]);
        $period = ReportPeriod::fromDates('2026-06-01', '2026-06-30');

        $this->order(CarbonImmutable::parse('2026-06-10'), [[$a, 2, 10], [$b, 1, 5]]);
        $this->order(CarbonImmutable::parse('2026-06-11'), [[$a, 100, 10]], 'cancelled');
        $this->order(CarbonImmutable::parse('2026-05-01'), [[$a, 100, 10]]); // outside period

        $result = $this->service->profitMargin($this->orgId, $period);

        $rowA = collect($result['products'])->firstWhere('sku', 'MA');
        $this->assertSame(20.0, $rowA['revenue']);   // subtotal, excludes tax
        $this->assertSame(12.0, $rowA['cogs']);
        $this->assertSame(8.0, $rowA['margin']);
        $this->assertSame(40.0, $rowA['margin_pct']);
        $this->assertFalse($rowA['cost_missing']);

        $rowB = collect($result['products'])->firstWhere('sku', 'MB');
        $this->assertTrue($rowB['cost_missing']);

        $cat = collect($result['categories'])->firstWhere('category', 'Gadgets');
        $this->assertSame(25.0, $cat['revenue']);
        $this->assertSame(12.0, $cat['cogs']);

        $this->assertSame(25.0, $result['summary']['revenue']);
        $this->assertSame(13.0, $result['summary']['margin']);
        $this->assertSame(1, $result['summary']['units_without_cost']);
        $this->assertSame('current_purchase_price', $result['cost_basis']);
    }

    // --------------------------------------------------------- sales by location

    public function test_sales_by_warehouse_and_location_with_previous_period_delta(): void
    {
        $w1 = $this->warehouse('North');
        $l1 = $this->location('Aisle 1', $w1);
        $p = $this->product('LOC', ['location_id' => $l1]);
        $period = ReportPeriod::fromDates('2026-06-11', '2026-06-20');

        $this->order(CarbonImmutable::parse('2026-06-15'), [[$p, 3, 10]], 'delivered', null, $w1); // 30
        $this->order(CarbonImmutable::parse('2026-06-05'), [[$p, 2, 10]], 'delivered', null, $w1); // previous: 20
        $this->order(CarbonImmutable::parse('2026-06-16'), [[$p, 1, 10]]); // no warehouse

        $result = $this->service->salesByLocation($this->orgId, $period);

        $north = collect($result['byWarehouse'])->firstWhere('name', 'North');
        $this->assertSame(1, $north['orders']);
        // Order totals (incl. tax), the same basis as Sales Analysis.
        $this->assertSame(33.0, $north['revenue']);
        $this->assertSame(22.0, $north['previous_revenue']);
        $this->assertSame(50.0, $north['delta_pct']);

        $unassigned = collect($result['byWarehouse'])->firstWhere('warehouse_id', null);
        $this->assertSame(11.0, $unassigned['revenue']);
        $this->assertNull($unassigned['delta_pct']); // no previous revenue

        $aisle = collect($result['byLocation'])->firstWhere('name', 'Aisle 1');
        $this->assertSame(4, $aisle['units']);
        $this->assertSame(44.0, $aisle['revenue']);
    }

    // ------------------------------------------------------ valuation by location

    public function test_valuation_by_location_includes_unallocated_stock(): void
    {
        $w = $this->warehouse('Main');
        $l1 = $this->location('Shelf A', $w);
        $l2 = $this->location('Shelf B', $w);
        $p = $this->product('VAL', ['stock' => 10, 'purchase_price' => 2, 'price' => 5]);
        foreach ([[$l1, 4], [$l2, 3]] as [$loc, $qty]) {
            DB::table('product_location_stocks')->insert([
                'organization_id' => $this->orgId, 'product_id' => $p, 'location_id' => $loc,
                'quantity' => $qty, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
        }

        $rows = $this->service->valuationByLocation($this->orgId);

        $a = collect($rows)->firstWhere('location', 'Shelf A');
        $this->assertSame(4, $a['quantity']);
        $this->assertSame(8.0, $a['cost_value']);
        $this->assertSame(20.0, $a['retail_value']);
        $this->assertSame('Main', $a['warehouse']);

        $unallocated = collect($rows)->firstWhere('location_id', null);
        $this->assertSame(3, $unallocated['quantity']);
        $this->assertSame(6.0, $unallocated['cost_value']);
    }

    // -------------------------------------------------------------------- ABC

    public function test_abc_classes_split_revenue_80_15_5(): void
    {
        $period = ReportPeriod::fromDates('2026-06-01', '2026-06-30');
        $revenues = ['A1' => 700, 'A2' => 150, 'B1' => 100, 'C1' => 30, 'C2' => 20];
        foreach ($revenues as $sku => $revenue) {
            $id = $this->product($sku);
            $this->order(CarbonImmutable::parse('2026-06-10'), [[$id, 1, $revenue]]);
        }

        $result = $this->service->abcAnalysis($this->orgId, $period);

        $classes = collect($result['rows'])->pluck('class', 'sku')->all();
        // A1 alone is 70% (<80% before it); A2 starts at 70% so is still A.
        $this->assertSame(['A1' => 'A', 'A2' => 'A', 'B1' => 'B', 'C1' => 'C', 'C2' => 'C'], $classes);
        $this->assertSame(85.0, collect($result['rows'])->firstWhere('sku', 'A2')['cumulative_pct']);
        $this->assertSame(2, $result['summary']['A']['count']);
        $this->assertSame(850.0, $result['summary']['A']['revenue']);
        $this->assertSame(1000.0, $result['total_revenue']);
    }
}
