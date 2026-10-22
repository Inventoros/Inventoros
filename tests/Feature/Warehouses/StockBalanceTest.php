<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductVariant;
use App\Services\ProductLocationStockService;
use App\Services\StockBalanceService;
use App\Services\StockReservationService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StockBalanceTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
    }

    public function test_balances_are_scoped_by_warehouse_and_include_unassigned_stock_only_for_unrestricted_staff(): void
    {
        $loose = $this->makeProduct('Loose', 'LOOSE', null, 7);
        $all = app(StockBalanceService::class)->paginate($this->admin)->items();
        $this->assertCount(4, $all);
        $this->assertSame(157, array_sum(array_column($all, 'on_hand')));
        $visible = app(StockBalanceService::class)->paginate($this->restricted)->items();
        $this->assertCount(1, $visible);
        $this->assertSame($this->warehouseA->id, $visible[0]['warehouse_id']);
        $this->assertSame(60, $visible[0]['on_hand']);
    }

    public function test_variants_are_separate_stock_pools_and_global_holds_cap_bin_availability(): void
    {
        $this->productA->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
            'sku' => 'BLUE-M', 'title' => 'Blue / M', 'option_values' => ['Color' => 'Blue'], 'stock' => 20, 'is_active' => true,
        ]);
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($variant, $this->locationA->id, $this->locationB->id, 7));
        app(StockReservationService::class)->reserve($this->productA, $variant, 15, 'core', 'global-hold');
        $rows = app(StockBalanceService::class)->paginate($this->admin, ['search' => 'BLUE-M'])->items();
        $this->assertCount(2, $rows);
        $this->assertSame(20, array_sum(array_column($rows, 'on_hand')));
        foreach ($rows as $row) {
            $this->assertSame($variant->id, $row['product_variant_id']);
            $this->assertSame(5, $row['available']);
        }
    }

    public function test_in_transit_stock_is_visible_at_destination_and_not_available_before_receipt(): void
    {
        $service = app(StockTransferService::class);
        $transfer = $service->create($this->organization->id, $this->admin, [
            'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->productA->id, 'quantity' => 10]],
        ]);
        $service->ship($transfer, $this->admin);
        $rows = app(StockBalanceService::class)->paginate($this->admin, ['search' => 'ALPHA-1'])->items();
        $destination = collect($rows)->firstWhere('location_id', $this->locationB->id);
        $this->assertSame(10, $destination['in_transit']);
        $this->assertSame(40, $destination['on_hand']);
        $this->assertSame(40, $destination['available']);
        $this->assertSame(90, array_sum(array_column($rows, 'on_hand')));
        $service->complete($transfer, $this->admin);
        $rows = app(StockBalanceService::class)->paginate($this->admin, ['search' => 'ALPHA-1'])->items();
        $this->assertSame(0, array_sum(array_column($rows, 'in_transit')));
        $this->assertSame(100, array_sum(array_column($rows, 'on_hand')));
    }

    public function test_every_sort_is_allowed_but_untrusted_sort_sql_is_never_executed(): void
    {
        foreach (StockBalanceService::SORTS as $sort) {
            $rows = app(StockBalanceService::class)->paginate($this->admin, ['sort_by' => $sort, 'sort_dir' => 'desc', 'per_page' => 1]);
            $this->assertCount(1, $rows->items());
            $this->assertSame(3, $rows->total());
        }
        $rows = app(StockBalanceService::class)->paginate($this->admin, ['sort_by' => 'stock); DROP TABLE products; --']);
        $this->assertSame(3, $rows->total());
        $this->assertDatabaseCount('products', 2);
    }
    public function test_web_and_rest_enforce_the_same_warehouse_scope(): void
    {
        $this->actingAs($this->restricted)->get(route('stock.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Stock/Index')->has('balances.data', 1)->where('balances.data.0.warehouse_id', $this->warehouseA->id));
        \Laravel\Sanctum\Sanctum::actingAs($this->restricted);
        $this->getJson('/api/v1/stock-balances?per_page=500')->assertOk()->assertJsonPath('per_page', 100)->assertJsonPath('total', 1);
        $this->getJson('/api/v1/stock-balances?warehouse_id='.$this->warehouseB->id)->assertForbidden();
    }

    public function test_mcp_returns_the_same_scoped_balances(): void
    {
        \App\Mcp\Servers\InventorosServer::actingAs($this->restricted)
            ->tool(\App\Mcp\Tools\ListStockBalancesTool::class, ['sort_by' => 'on_hand'])
            ->assertOk()->assertSee('Alpha Warehouse')->assertDontSee('Bravo Warehouse');
    }

    public function test_csv_neutralises_formula_cells_and_requires_export_permission(): void
    {
        $this->productA->update(['sku' => '=FORMULA']);
        $response = $this->actingAs($this->admin)->get(route('stock.export', ['search' => '=FORMULA']));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=FORMULA", $csv);
        $this->assertStringNotContainsString('Bravo Widget', $csv);
        $this->actingAs($this->restricted)->get(route('stock.export'))->assertForbidden();
    }

    public function test_zero_unassigned_balances_are_available_to_the_out_of_stock_filter(): void
    {
        $this->makeProduct('Empty', 'EMPTY', null, 0);
        $rows = app(StockBalanceService::class)->paginate($this->admin, ['search' => 'EMPTY'])->items();
        $this->assertCount(0, $rows);
        $rows = app(StockBalanceService::class)->paginate($this->admin, ['search' => 'EMPTY', 'out_of_stock' => true])->items();
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['on_hand']);
    }

}
