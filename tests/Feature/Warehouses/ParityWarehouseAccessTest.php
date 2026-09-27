<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The stock-transfer and stock-audit endpoints added for API parity, and the
 * GraphQL transfer and purchase-order mutations, apply the same warehouse
 * restriction as the web app: the shared services enforce it, so no surface
 * can skip it.
 */
class ParityWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        Sanctum::actingAs($this->restricted, ['*']);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function gql(string $query, array $variables = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/graphql', ['query' => $query, 'variables' => $variables]);
    }

    public function test_rest_transfer_list_and_show_hide_other_warehouses(): void
    {
        $mine = $this->makeTransfer($this->locationA, $this->locationA, $this->productA);
        $theirs = $this->makeTransfer($this->locationB, $this->locationB, $this->productB);

        $this->getJson('/api/v1/stock-transfers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson("/api/v1/stock-transfers/{$theirs->id}")->assertForbidden();
    }

    public function test_rest_cannot_send_stock_out_of_another_warehouse(): void
    {
        $this->postJson('/api/v1/stock-transfers', [
            'from_location_id' => $this->locationB->id,
            'to_location_id' => $this->locationA->id,
            'items' => [['product_id' => $this->productB->id, 'quantity' => 1]],
        ])->assertForbidden();

        // Sending from their own warehouse to another is allowed.
        $this->postJson('/api/v1/stock-transfers', [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->productA->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    public function test_rest_cannot_act_on_another_warehouses_transfer(): void
    {
        $theirs = $this->makeTransfer($this->locationB, $this->locationB, $this->productB);

        $this->postJson("/api/v1/stock-transfers/{$theirs->id}/ship")->assertForbidden();
        $this->postJson("/api/v1/stock-transfers/{$theirs->id}/complete")->assertForbidden();
        $this->postJson("/api/v1/stock-transfers/{$theirs->id}/cancel")->assertForbidden();

        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_rest_audit_writes_are_limited_to_own_warehouses(): void
    {
        $this->postJson('/api/v1/stock-audits', [
            'name' => 'Sneaky', 'audit_type' => 'cycle', 'warehouse_location_id' => $this->locationB->id,
        ])->assertForbidden();

        $theirs = $this->makeAudit($this->locationB, 'Bravo count');
        $theirs->items()->create(['product_id' => $this->productB->id, 'location_id' => $this->locationB->id, 'system_quantity' => 50, 'status' => 'pending']);

        $this->postJson("/api/v1/stock-audits/{$theirs->id}/start")->assertForbidden();
        $this->assertSame('draft', $theirs->fresh()->status);

        $theirs->update(['status' => 'in_progress']);
        $item = $theirs->items()->first();
        $this->postJson("/api/v1/stock-audits/{$theirs->id}/items/{$item->id}/count", ['counted_quantity' => 1])->assertForbidden();
        $this->postJson("/api/v1/stock-audits/{$theirs->id}/complete")->assertForbidden();

        $this->assertSame(50, $this->productB->fresh()->stock);
    }

    public function test_graphql_transfers_are_limited_to_own_warehouses(): void
    {
        $this->makeTransfer($this->locationA, $this->locationA, $this->productA);
        $theirs = $this->makeTransfer($this->locationB, $this->locationB, $this->productB);

        $this->gql('{ stockTransfers { id } }')->assertJsonCount(1, 'data.stockTransfers');
        $this->assertNotEmpty($this->gql("{ stockTransfer(id: {$theirs->id}) { id } }")->json('errors'));

        $this->assertNotEmpty($this->gql("mutation { completeStockTransfer(id: {$theirs->id}) { id } }")->json('errors'));
        $this->assertSame('pending', $theirs->fresh()->status);

        $response = $this->gql(
            'mutation($items: [StockTransferItemInput!]!) { createStockTransfer(from_location_id: '.$this->locationB->id.', to_location_id: '.$this->locationA->id.', items: $items) { id } }',
            ['items' => [['product_id' => $this->productB->id, 'quantity' => 1]]]
        );
        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(2, StockTransfer::count());
    }

    public function test_graphql_cannot_receive_into_another_warehouse(): void
    {
        [$po, $item] = $this->makeSentPurchaseOrder($this->productB);

        $response = $this->gql(
            'mutation($items: [PurchaseOrderReceiveItemInput!]!) { receivePurchaseOrder(id: '.$po->id.', items: $items) { status } }',
            ['items' => [['id' => $item->id, 'quantity_to_receive' => 5]]]
        );

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, $item->fresh()->quantity_received);
        $this->assertSame(50, $this->productB->fresh()->stock);
    }
}
