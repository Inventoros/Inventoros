<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\Inventory\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Transfer approval is enforced by StockTransferService, so a transfer
 * created over REST or GraphQL is held for approval exactly like one created
 * on the web, and cannot ship or complete until approved.
 */
class TransferApprovalParityTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
        Mail::fake();
        $this->enableApprovals(['stock_transfers_enabled' => true]);
    }

    public function test_rest_transfer_is_held_and_cannot_ship_or_complete(): void
    {
        Sanctum::actingAs($this->requester);

        $id = $this->postJson('/api/v1/stock-transfers', [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');

        $transfer = StockTransfer::findOrFail($id);
        $this->assertSame(StockTransfer::APPROVAL_PENDING, $transfer->approval_status);

        $this->postJson("/api/v1/stock-transfers/{$id}/ship")->assertStatus(422)->assertJsonPath('error', 'invalid_state');
        $this->postJson("/api/v1/stock-transfers/{$id}/complete")->assertStatus(422)->assertJsonPath('error', 'invalid_state');

        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_graphql_transfer_is_held_and_cannot_complete(): void
    {
        Sanctum::actingAs($this->requester);

        $id = $this->postJson('/graphql', [
            'query' => 'mutation($items: [StockTransferItemInput!]!) { createStockTransfer(from_location_id: '.$this->locationA->id.', to_location_id: '.$this->locationB->id.', items: $items) { id } }',
            'variables' => ['items' => [['product_id' => $this->product->id, 'quantity' => 2]]],
        ])->json('data.createStockTransfer.id');

        $this->assertSame(StockTransfer::APPROVAL_PENDING, StockTransfer::findOrFail($id)->approval_status);

        $response = $this->postJson('/graphql', ['query' => "mutation { completeStockTransfer(id: {$id}) { status } }"]);
        $this->assertStringContainsString('waiting for approval', $response->json('errors.0.message'));
        $this->assertSame('pending', StockTransfer::findOrFail($id)->status);
    }
}
