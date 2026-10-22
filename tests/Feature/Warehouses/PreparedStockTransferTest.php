<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockTransfer;
use App\Services\ProductLocationStockService;
use App\Services\StockReservationService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PreparedStockTransferTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $this->warehouseB->users()->attach($this->unassigned->id);
    }

    private function transfer(?ProductVariant $variant = null, int $quantity = 15): StockTransfer
    {
        return app(StockTransferService::class)->create($this->organization->id, $this->restricted, [
            'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->productA->id, 'product_variant_id' => $variant?->id, 'quantity' => $quantity]],
        ]);
    }

    public function test_preparing_reserves_source_stock_and_cancelling_releases_it(): void
    {
        $transfer = $this->transfer();
        $service = app(StockTransferService::class);
        $service->prepare($transfer, $this->restricted);
        $this->assertSame('prepared', $transfer->status);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(85, app(StockReservationService::class)->available($this->productA));
        $this->assertSame(45, app(StockReservationService::class)->available($this->productA, locationId: $this->locationA->id));
        $service->cancel($transfer, $this->restricted);
        $this->assertSame(100, app(StockReservationService::class)->available($this->productA));
    }

    public function test_shipping_removes_source_stock_and_receiving_credits_only_destination_once(): void
    {
        $transfer = $this->transfer();
        $service = app(StockTransferService::class);
        $service->prepare($transfer, $this->restricted);
        $service->ship($transfer, $this->restricted, ['tracking_number' => 'TRACK-42']);
        $this->assertSame('in_transit', $transfer->status);
        $this->assertNotNull($transfer->stock_dispatched_at);
        $this->assertSame(85, $this->productA->fresh()->stock);
        try {
            $service->ship($transfer, $this->restricted);
            $this->fail('A transfer may ship only once');
        } catch (\App\Exceptions\InvalidStateException) {
            $this->assertSame(85, $this->productA->fresh()->stock);
        }

        $this->assertSame(45, app(ProductLocationStockService::class)->quantityAt($this->productA, $this->locationA->id));
        $this->assertSame(40, app(ProductLocationStockService::class)->quantityAt($this->productA, $this->locationB->id));
        $service->complete($transfer, $this->unassigned);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(55, app(ProductLocationStockService::class)->quantityAt($this->productA, $this->locationB->id));
        $this->assertDatabaseHas('stock_adjustments', ['reference_id' => $transfer->id, 'type' => 'transfer_shipped', 'adjustment_quantity' => -15]);
        $this->assertDatabaseHas('stock_adjustments', ['reference_id' => $transfer->id, 'type' => 'transfer_received', 'adjustment_quantity' => 15]);
        $this->expectException(\App\Exceptions\InvalidStateException::class);
        $service->complete($transfer, $this->unassigned);
    }

    public function test_variants_travel_independently_of_parent_stock_and_primary_location(): void
    {
        $this->productA->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->productA->id,
            'sku' => 'BLUE-M', 'option_values' => ['Color' => 'Blue'], 'stock' => 20, 'is_active' => true,
        ]);
        $transfer = $this->transfer($variant, 7);
        $service = app(StockTransferService::class);
        $service->ship($transfer, $this->restricted);
        $this->assertSame(13, $variant->fresh()->stock);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $service->complete($transfer, $this->unassigned);
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertSame(7, app(ProductLocationStockService::class)->quantityAt($variant, $this->locationB->id));
        $this->assertSame($this->locationA->id, $this->productA->fresh()->location_id);
    }

    public function test_existing_in_transit_transfers_keep_their_undispatched_stock_compatibility_path(): void
    {
        $transfer = $this->transfer();
        $transfer->update(['status' => 'in_transit', 'shipped_at' => now()]);
        app(StockTransferService::class)->complete($transfer, $this->unassigned);
        $this->assertSame(100, $this->productA->fresh()->stock);
        $this->assertSame(55, app(ProductLocationStockService::class)->quantityAt($this->productA, $this->locationB->id));
    }

    public function test_source_staff_cannot_receive_and_destination_staff_cannot_ship(): void
    {
        $transfer = $this->transfer();
        Sanctum::actingAs($this->unassigned);
        $this->postJson('/api/v1/stock-transfers/'.$transfer->id.'/ship')->assertForbidden();
        Sanctum::actingAs($this->restricted);
        $this->postJson('/api/v1/stock-transfers/'.$transfer->id.'/prepare')->assertOk()->assertJsonPath('data.status', 'prepared');
        $this->postJson('/api/v1/stock-transfers/'.$transfer->id.'/ship')->assertOk();
        $this->postJson('/api/v1/stock-transfers/'.$transfer->id.'/complete')->assertForbidden();
        $this->postJson('/api/v1/stock-transfers/'.$transfer->id.'/cancel')->assertStatus(422);
        Sanctum::actingAs($this->unassigned);
        $this->postJson('/api/v1/stock-transfers/'.$transfer->id.'/complete')->assertOk();
    }

    public function test_graphql_uses_the_same_prepared_transfer_workflow(): void
    {
        $transfer = $this->transfer();
        Sanctum::actingAs($this->restricted);
        $query = sprintf('mutation { transitionStockTransfer(id: %d, action: "prepare") { id status } }', $transfer->id);
        $this->postJson('/graphql', ['query' => $query])->assertJsonMissingPath('errors')->assertJsonPath('data.transitionStockTransfer.status', 'prepared');
        $this->assertSame(85, app(StockReservationService::class)->available($this->productA));
    }
    public function test_mcp_uses_the_same_workflow_and_warehouse_permissions(): void
    {
        $transfer = $this->transfer();
        \App\Mcp\Servers\InventorosServer::actingAs($this->unassigned)
            ->tool(\App\Mcp\Tools\TransitionStockTransferTool::class, ['id' => $transfer->id, 'action' => 'prepare'])
            ->assertHasErrors(['access']);
    }

    public function test_mcp_prepares_a_transfer_for_source_warehouse_staff(): void
    {
        $transfer = $this->transfer();
        $response = \App\Mcp\Servers\InventorosServer::actingAs($this->restricted)
            ->tool(\App\Mcp\Tools\TransitionStockTransferTool::class, ['id' => $transfer->id, 'action' => 'prepare']);
        $response->assertOk()->assertSee('prepared');
        $this->assertSame(85, app(StockReservationService::class)->available($this->productA));
    }

}
