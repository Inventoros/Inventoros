<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\AdjustStockTool;
use App\Mcp\Tools\ListLocationsTool;
use App\Mcp\Tools\ListWarehousesTool;
use App\Mcp\Tools\ReceivePurchaseOrderTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GraphQL and MCP apply the same warehouse restriction as web and REST.
 */
class GraphQlAndMcpWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
    }

    // GraphQL

    public function test_graphql_locations_hide_other_warehouses(): void
    {
        Sanctum::actingAs($this->restricted, ['*']);

        $response = $this->postJson('/graphql', ['query' => '{ locations { id name } }']);

        $response->assertJsonPath('data.locations.0.name', 'Alpha Shelf');
        $this->assertCount(1, $response->json('data.locations'));
    }

    public function test_graphql_cannot_adjust_stock_in_another_warehouse(): void
    {
        Sanctum::actingAs($this->restricted, ['*']);

        foreach ([sprintf('location_id: %d', $this->locationB->id), ''] as $locationArg) {
            $query = sprintf(
                'mutation { createStockAdjustment(product_id: %d, quantity: -5, type: "damage" %s) { id } }',
                $this->productA->id,
                $locationArg,
            );

            $response = $this->postJson('/graphql', ['query' => $query]);
            $this->assertNotEmpty($response->json('errors'));
        }

        $this->assertSame(100, $this->productA->fresh()->stock);

        $query = sprintf(
            'mutation { createStockAdjustment(product_id: %d, quantity: -5, type: "damage", location_id: %d) { id } }',
            $this->productA->id,
            $this->locationA->id,
        );
        $this->postJson('/graphql', ['query' => $query])->assertJsonMissingPath('errors');

        $this->assertSame(95, $this->productA->fresh()->stock);
        $this->assertDatabaseHas('stock_adjustments', ['product_id' => $this->productA->id, 'location_id' => $this->locationA->id]);
    }

    public function test_graphql_adjustment_history_hides_other_warehouses(): void
    {
        $this->makeAdjustment($this->productA, $this->locationA, 'Alpha recount');
        $this->makeAdjustment($this->productA, $this->locationB, 'Bravo recount');

        Sanctum::actingAs($this->restricted, ['*']);

        $response = $this->postJson('/graphql', ['query' => '{ stockAdjustments { id reason } }']);

        $this->assertSame(['Alpha recount'], array_column($response->json('data.stockAdjustments'), 'reason'));
    }

    // MCP

    public function test_mcp_lists_hide_other_warehouses(): void
    {
        InventorosServer::actingAs($this->restricted)
            ->tool(ListLocationsTool::class)
            ->assertOk()
            ->assertSee('Alpha Shelf')
            ->assertDontSee('Bravo Shelf');

        InventorosServer::actingAs($this->restricted)
            ->tool(ListWarehousesTool::class)
            ->assertOk()
            ->assertSee('WH-ALPHA')
            ->assertDontSee('WH-BRAVO');
    }

    public function test_mcp_cannot_adjust_stock_in_another_warehouse(): void
    {
        InventorosServer::actingAs($this->restricted)
            ->tool(AdjustStockTool::class, [
                'product_id' => $this->productA->id,
                'quantity' => -5,
                'type' => 'damage',
                'location_id' => $this->locationB->id,
            ])
            ->assertHasErrors(['access']);

        InventorosServer::actingAs($this->restricted)
            ->tool(AdjustStockTool::class, [
                'product_id' => $this->productA->id,
                'quantity' => -5,
                'type' => 'damage',
            ])
            ->assertHasErrors(['access']);

        $this->assertSame(100, $this->productA->fresh()->stock);

        InventorosServer::actingAs($this->restricted)
            ->tool(AdjustStockTool::class, [
                'product_id' => $this->productA->id,
                'quantity' => -5,
                'type' => 'damage',
                'location_id' => $this->locationA->id,
            ])
            ->assertOk();

        $this->assertSame(95, $this->productA->fresh()->stock);
    }

    public function test_mcp_cannot_receive_purchase_order_into_another_warehouse(): void
    {
        [$po, $item] = $this->makeSentPurchaseOrder($this->productB);

        InventorosServer::actingAs($this->restricted)
            ->tool(ReceivePurchaseOrderTool::class, [
                'id' => $po->id,
                'items' => [['id' => $item->id, 'quantity_to_receive' => 3]],
            ])
            ->assertHasErrors(['access']);

        $this->assertSame(0, $item->fresh()->quantity_received);
    }
}
