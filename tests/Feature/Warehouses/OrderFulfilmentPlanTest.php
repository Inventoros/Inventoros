<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\PlanOrderFulfilmentTool;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Services\OrderFulfilmentPlanner;
use App\Services\ProductLocationStockService;
use App\Services\StockReservationService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OrderFulfilmentPlanTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
    }

    private function items(int $quantity = 10): array
    {
        return [['product_id' => $this->productA->id, 'quantity' => $quantity]];
    }

    public function test_whole_order_coverage_ranks_before_priority_and_combines_duplicate_lines(): void
    {
        $this->warehouseA->update(['priority' => 100]);
        $plan = app(OrderFulfilmentPlanner::class)->plan($this->admin, [
            ...$this->items(10), ...$this->items(20), ['product_id' => $this->productB->id, 'quantity' => 30],
        ]);
        $this->assertSame($this->warehouseB->id, $plan['recommended_warehouse_id']);
        $this->assertTrue($plan['candidates'][0]['can_fulfil']);
        $this->assertCount(2, $plan['candidates'][0]['lines']);
        $this->assertSame(30, $plan['candidates'][0]['lines'][0]['quantity']);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_scoped_warehouses_do_not_leak_sources_and_stock_filters_only_requested_products(): void
    {
        $plan = app(OrderFulfilmentPlanner::class)->plan($this->restricted, $this->items(70));
        $this->assertCount(1, $plan['candidates']);
        $candidate = $plan['candidates'][0];
        $this->assertSame(60, $candidate['lines'][0]['available']);
        $this->assertSame(10, $candidate['uncovered_units']);
        $this->assertSame([], $candidate['transfers']);
        $this->assertStringNotContainsString('Bravo', json_encode($plan));
        $this->assertCount(1, $candidate['lines']);
    }

    public function test_global_holds_cap_suggestions_across_multiple_source_bins(): void
    {
        app(StockReservationService::class)->reserve($this->productA, null, 80, 'core', 'global');
        $candidate = collect(app(OrderFulfilmentPlanner::class)->plan($this->admin, $this->items(70))['candidates'])->firstWhere('warehouse_id', $this->warehouseA->id);
        $this->assertSame(20, $candidate['lines'][0]['available']);
        $this->assertSame(50, $candidate['uncovered_units']);
        $this->assertSame([], $candidate['transfers']);
    }

    public function test_incoming_stock_is_not_ready_and_does_not_generate_duplicate_transfers(): void
    {
        $service = app(StockTransferService::class);
        $transfer = $service->create($this->organization->id, $this->admin, [
            'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id, 'items' => $this->items(10),
        ]);
        $service->ship($transfer, $this->admin);
        $candidate = collect(app(OrderFulfilmentPlanner::class)->plan($this->admin, $this->items(50))['candidates'])->firstWhere('warehouse_id', $this->warehouseB->id);
        $this->assertFalse($candidate['can_fulfil']);
        $this->assertSame(40, $candidate['lines'][0]['available']);
        $this->assertSame(10, $candidate['lines'][0]['in_transit']);
        $this->assertSame(0, $candidate['uncovered_units']);
        $this->assertSame([], $candidate['transfers']);
    }

    public function test_variant_bins_and_holds_produce_prefillable_transfer_suggestions(): void
    {
        $this->productA->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
            'sku' => 'PLAN-RED', 'title' => 'Red', 'stock' => 20, 'is_active' => true, 'option_values' => ['Colour' => 'Red'],
        ]);
        DB::transaction(fn () => app(ProductLocationStockService::class)->move($variant, $this->locationA->id, $this->locationB->id, 5));
        app(StockReservationService::class)->reserve($this->productA, $variant, 3, 'core', 'held', locationId: $this->locationA->id);
        $plan = app(OrderFulfilmentPlanner::class)->plan($this->admin, [['product_id' => $this->productA->id, 'product_variant_id' => $variant->id, 'quantity' => 10]]);
        $candidate = collect($plan['candidates'])->firstWhere('warehouse_id', $this->warehouseB->id);
        $this->assertSame(5, $candidate['lines'][0]['available']);
        $suggestion = $candidate['transfers'][0];
        $this->assertSame(5, $suggestion['quantity']);
        $this->assertSame($variant->id, $suggestion['product_variant_id']);
        $this->actingAs($this->admin)->get(route('stock-transfers.create', array_intersect_key($suggestion, array_flip(['from_location_id', 'to_location_id', 'product_id', 'product_variant_id', 'quantity']))))
            ->assertOk()->assertInertia(fn ($page) => $page->where('prefill.product_variant_id', $variant->id)->where('prefill.quantity', 5));
        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_mismatched_variants_and_foreign_products_are_rejected(): void
    {
        $foreign = Product::factory()->create();
        foreach ([['product_id' => $foreign->id, 'quantity' => 1], ['product_id' => $this->productA->id, 'product_variant_id' => 9999999, 'quantity' => 1]] as $item) {
            try {
                app(OrderFulfilmentPlanner::class)->plan($this->admin, [$item]);
                $this->fail('Invalid product selection was accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_rest_web_and_mcp_have_scoped_read_only_plans(): void
    {
        $this->actingAs($this->restricted)->postJson(route('orders.fulfilment-plan'), ['items' => $this->items()])->assertForbidden();
        Sanctum::actingAs($this->restricted);
        $this->postJson('/api/v1/orders/fulfilment-plan', ['items' => $this->items()])->assertOk()->assertJsonCount(1, 'candidates');
        InventorosServer::actingAs($this->restricted)->tool(PlanOrderFulfilmentTool::class, ['items' => $this->items()])->assertOk()->assertSee('Alpha Warehouse')->assertDontSee('Bravo Warehouse');
        $this->actingAs($this->admin)->postJson(route('orders.fulfilment-plan'), ['items' => $this->items()])->assertOk();
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_prefill_rejects_inaccessible_sources_and_invalid_quantities(): void
    {
        $query = ['from_location_id' => $this->locationB->id, 'to_location_id' => $this->locationA->id, 'product_id' => $this->productA->id, 'quantity' => 1];
        $this->actingAs($this->restricted)->get(route('stock-transfers.create', $query))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('stock-transfers.create', $query + ['product_variant_id' => 9999999]))->assertUnprocessable();
    }
}
