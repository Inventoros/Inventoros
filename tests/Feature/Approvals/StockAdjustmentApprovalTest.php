<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Mail\ApprovalEmail;
use App\Mcp\Tools\AdjustStockTool;
use App\Mcp\Tools\DecideApprovalTool;
use App\Mcp\Tools\ListPendingApprovalsTool;
use App\Models\ActivityLog;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockAdjustmentApprovalTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
    }

    private function webAdjust(int $quantity, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->requester)->post(route('stock-adjustments.store'), array_merge([
            'product_id' => $this->product->id,
            'type' => 'damage',
            'adjustment_quantity' => $quantity,
            'reason' => 'Dropped a pallet',
        ], $extra));
    }

    public function test_adjustments_apply_immediately_when_approvals_are_off(): void
    {
        $this->webAdjust(-5)->assertSessionHas('success');

        $this->assertSame(95, $this->product->fresh()->stock);
        $this->assertSame(0, StockAdjustmentRequest::count());
    }

    public function test_an_adjustment_needing_approval_is_held_and_stock_is_untouched(): void
    {
        Mail::fake();
        $this->enableApprovals(['stock_adjustments_enabled' => true]);

        $this->webAdjust(-5)->assertSessionHas('success');

        // The invariant: nothing moves until an approver says yes.
        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame(0, StockAdjustment::count());

        $request = StockAdjustmentRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertSame(-5, $request->quantity);
        $this->assertSame($this->requester->id, $request->requested_by);
        $this->assertEquals(10.0, (float) $request->value, 'Value is |qty| x purchase price.');

        $this->assertTrue(Notification::where('user_id', $this->approver->id)->where('type', 'approval_requested')->exists());
        $this->assertFalse(Notification::where('user_id', $this->requester->id)->where('type', 'approval_requested')->exists());
        Mail::assertQueued(ApprovalEmail::class, fn (ApprovalEmail $m) => $m->hasTo('abe@acme.test'));
        $this->assertTrue(ActivityLog::where('subject_type', StockAdjustmentRequest::class)->where('action', 'approval_requested')->exists());
    }

    public function test_stock_moves_only_on_approval_through_the_audited_ledger(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);
        $this->webAdjust(-5, ['location_id' => $this->locationA->id]);
        $request = StockAdjustmentRequest::sole();

        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'stock_adjustment', 'id' => $request->id]))
            ->assertSessionHas('success');

        $this->assertSame(95, $this->product->fresh()->stock);

        $ledger = StockAdjustment::sole();
        $this->assertSame(100, $ledger->quantity_before);
        $this->assertSame(95, $ledger->quantity_after);
        $this->assertSame('damage', $ledger->type);
        $this->assertSame($this->requester->id, $ledger->user_id, 'The ledger row is attributed to whoever asked for the change.');
        $this->assertSame(StockAdjustmentRequest::class, $ledger->reference_type);
        $this->assertSame($request->id, $ledger->reference_id);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($ledger->id, $request->stock_adjustment_id);
        $this->assertSame($this->approver->id, $request->approved_by);

        $this->assertTrue(Notification::where('user_id', $this->requester->id)->where('type', 'approval_approved')->exists());
    }

    public function test_approval_revalidates_negative_stock(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);
        $this->webAdjust(-80);
        $request = StockAdjustmentRequest::sole();

        // Stock drops before the approver gets to it.
        StockAdjustment::adjust($this->product, -50, 'manual', 'Sold at the counter');

        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'stock_adjustment', 'id' => $request->id]))
            ->assertSessionHas('error');

        $this->assertSame(50, $this->product->fresh()->stock);
        $this->assertSame('pending', $request->fresh()->status, 'A refused approval leaves the request open.');
    }

    public function test_rejection_never_moves_stock(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);
        $this->webAdjust(-5);
        $request = StockAdjustmentRequest::sole();

        $this->actingAs($this->approver)
            ->post(route('approvals.reject', ['type' => 'stock_adjustment', 'id' => $request->id]), ['notes' => 'Recount first'])
            ->assertSessionHas('success');

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertTrue(Notification::where('user_id', $this->requester->id)->where('type', 'approval_rejected')->exists());
    }

    public function test_quantity_and_value_thresholds(): void
    {
        $this->enableApprovals([
            'stock_adjustments_enabled' => true,
            'stock_adjustments_quantity_threshold' => 10,
            'stock_adjustments_value_threshold' => 100,
        ]);

        // Small: applied straight away.
        $this->webAdjust(-3);
        $this->assertSame(97, $this->product->fresh()->stock);
        $this->assertSame(0, StockAdjustmentRequest::count());

        // Over the quantity threshold (|+12| > 10): held.
        $this->webAdjust(12, ['type' => 'correction']);
        $this->assertSame(97, $this->product->fresh()->stock);
        $this->assertSame(1, StockAdjustmentRequest::count());

        // Under the quantity threshold but over the value threshold.
        $this->product->update(['purchase_price' => 50]);
        $this->webAdjust(-3);
        $this->assertSame(97, $this->product->fresh()->stock);
        $this->assertSame(2, StockAdjustmentRequest::count());
    }

    public function test_variant_adjustments_are_held_and_applied_to_the_variant(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);
        $this->product->update(['has_variants' => true]);
        $variant = ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $this->product->id,
            'sku' => 'B-1-RED', 'title' => 'Red', 'option_values' => ['Color' => 'Red'], 'stock' => 20, 'is_active' => true,
        ]);

        $this->webAdjust(-4, ['product_variant_id' => $variant->id]);
        $this->assertSame(20, $variant->fresh()->stock);

        $request = StockAdjustmentRequest::sole();
        $this->assertSame($variant->id, $request->product_variant_id);

        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'stock_adjustment', 'id' => $request->id]))
            ->assertSessionHas('success');

        $this->assertSame(16, $variant->fresh()->stock);
        $this->assertSame($this->requester->id, StockAdjustment::sole()->user_id);
    }

    public function test_self_approval_and_permission_are_enforced(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);

        $this->actingAs($this->approver)->post(route('stock-adjustments.store'), [
            'product_id' => $this->product->id, 'type' => 'damage', 'adjustment_quantity' => -5, 'reason' => 'x',
        ]);
        $own = StockAdjustmentRequest::sole();

        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'stock_adjustment', 'id' => $own->id]))
            ->assertSessionHas('error');
        $this->assertSame(100, $this->product->fresh()->stock);

        $this->actingAs($this->requester)
            ->post(route('approvals.approve', ['type' => 'stock_adjustment', 'id' => $own->id]))
            ->assertForbidden();
        $this->assertSame(100, $this->product->fresh()->stock);
    }

    public function test_api_holds_the_adjustment_and_returns_202(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);
        Sanctum::actingAs($this->requester);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id, 'quantity' => -5, 'type' => 'damage', 'reason' => 'x',
        ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending_approval')
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(100, $this->product->fresh()->stock);

        $request = StockAdjustmentRequest::sole();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/approvals/stock_adjustment/{$request->id}/approve")->assertOk();
        $this->assertSame(95, $this->product->fresh()->stock);
    }

    private function makeVariant(int $stock = 20): ProductVariant
    {
        $this->product->update(['has_variants' => true]);

        return ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $this->product->id,
            'sku' => 'B-1-RED', 'title' => 'Red', 'option_values' => ['Color' => 'Red'], 'stock' => $stock, 'is_active' => true,
        ]);
    }

    public function test_api_adjusts_a_variant_directly_when_approvals_are_off(): void
    {
        $variant = $this->makeVariant();
        Sanctum::actingAs($this->requester);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id, 'product_variant_id' => $variant->id,
            'quantity' => -4, 'type' => 'damage',
        ])->assertCreated();

        $this->assertSame(16, $variant->fresh()->stock);
        $this->assertSame(100, $this->product->fresh()->stock, 'Product total is not the variant ledger.');
        $this->assertSame($variant->id, StockAdjustment::sole()->product_variant_id);
    }

    public function test_api_variant_adjustment_cannot_go_negative(): void
    {
        $variant = $this->makeVariant(3);
        Sanctum::actingAs($this->requester);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id, 'product_variant_id' => $variant->id,
            'quantity' => -5, 'type' => 'damage',
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->assertSame(3, $variant->fresh()->stock);
    }

    public function test_api_rejects_a_variant_of_another_product_or_with_a_location(): void
    {
        $variant = $this->makeVariant();
        $other = \App\Models\Inventory\Product::create([
            'organization_id' => $this->org->id, 'sku' => 'N-1', 'name' => 'Nut', 'price' => 1, 'currency' => 'USD', 'stock' => 5, 'is_active' => true,
        ]);
        Sanctum::actingAs($this->requester);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $other->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'type' => 'manual',
        ])->assertStatus(422)->assertJsonValidationErrors('product_variant_id');

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'type' => 'manual',
            'location_id' => $this->locationA->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');

        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_api_holds_a_variant_adjustment_for_approval_and_applies_it_to_the_variant(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);
        $variant = $this->makeVariant();
        Sanctum::actingAs($this->requester);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id, 'product_variant_id' => $variant->id,
            'quantity' => -4, 'type' => 'damage',
        ])->assertStatus(202)->assertJsonPath('status', 'pending_approval');

        $this->assertSame(20, $variant->fresh()->stock);
        $request = StockAdjustmentRequest::sole();
        $this->assertSame($variant->id, $request->product_variant_id);

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/approvals/stock_adjustment/{$request->id}/approve")->assertOk();

        $this->assertSame(16, $variant->fresh()->stock);
        $this->assertSame($variant->id, StockAdjustment::sole()->product_variant_id);
    }

    public function test_held_adjustment_carries_its_location_to_approval(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);
        \App\Models\Inventory\ProductLocationStock::create([
            'organization_id' => $this->org->id, 'product_id' => $this->product->id,
            'location_id' => $this->locationA->id, 'quantity' => 100,
        ]);
        Sanctum::actingAs($this->requester);

        $this->postJson('/api/v1/stock-adjustments', [
            'product_id' => $this->product->id, 'quantity' => -5, 'type' => 'damage', 'location_id' => $this->locationA->id,
        ])->assertStatus(202);

        $request = StockAdjustmentRequest::sole();
        $this->assertSame($this->locationA->id, $request->location_id);

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/approvals/stock_adjustment/{$request->id}/approve")->assertOk();

        $this->assertSame(95, $this->product->fresh()->stock);
        $this->assertSame(95, (int) \App\Models\Inventory\ProductLocationStock::where('product_id', $this->product->id)
            ->where('location_id', $this->locationA->id)->value('quantity'));
    }

    public function test_mcp_holds_the_adjustment_and_approves_through_the_service(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);

        $this->mcpAs($this->requester)
            ->tool(AdjustStockTool::class, ['product_id' => $this->product->id, 'quantity' => -5, 'type' => 'damage'])
            ->assertOk()
            ->assertSee('pending_approval');
        $this->assertSame(100, $this->product->fresh()->stock);

        $request = StockAdjustmentRequest::sole();

        $this->mcpAs($this->approver)
            ->tool(ListPendingApprovalsTool::class, [])
            ->assertOk()
            ->assertSee('stock_adjustment');

        $this->mcpAs($this->approver)
            ->tool(DecideApprovalTool::class, ['type' => 'stock_adjustment', 'id' => $request->id, 'decision' => 'approve'])
            ->assertOk();
        $this->assertSame(95, $this->product->fresh()->stock);
    }

    public function test_graphql_refuses_a_direct_adjustment_that_needs_approval_and_can_request_one(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);
        Sanctum::actingAs($this->requester, ['*']);

        $this->postJson('/graphql', ['query' => sprintf(
            'mutation { createStockAdjustment(product_id: %d, quantity: -5, type: "damage") { id } }',
            $this->product->id
        )])->assertJsonPath('errors.0.message', 'This adjustment needs approval. Submit it with requestStockAdjustmentApproval.');

        $this->assertSame(100, $this->product->fresh()->stock);
        $this->assertSame(0, StockAdjustmentRequest::count());

        $this->postJson('/graphql', ['query' => sprintf(
            'mutation { requestStockAdjustmentApproval(product_id: %d, quantity: -5, type: "damage") { type id status } }',
            $this->product->id
        )])->assertJsonPath('data.requestStockAdjustmentApproval.status', 'pending');

        $this->assertSame(100, $this->product->fresh()->stock);
        $request = StockAdjustmentRequest::sole();

        Sanctum::actingAs($this->approver, ['*']);
        $this->postJson('/graphql', ['query' => "mutation { decideApproval(type: \"stock_adjustment\", id: {$request->id}, decision: \"approve\") { status } }"])
            ->assertJsonPath('data.decideApproval.status', 'approved');
        $this->assertSame(95, $this->product->fresh()->stock);
    }
}
