<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Mail\PurchaseOrderEmail;
use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A header-only edit (shipping or tax, no lines) must move the PO total, so
 * the approval threshold is judged on what the supplier will actually be
 * asked to pay. Before the fix the total was only recomputed when `items`
 * was sent, so a 500 PO could carry 500,000 of shipping and still send
 * without approval.
 */
class PurchaseOrderThresholdBypassTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
        $this->enableApprovals(['purchase_orders_enabled' => true, 'purchase_orders_threshold' => 1000]);
    }

    public function test_rest_shipping_only_update_recomputes_the_total_and_blocks_send(): void
    {
        Mail::fake();
        $po = $this->draftPo(500);

        Sanctum::actingAs($this->requester);
        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['shipping' => 500000])->assertOk();

        $po->refresh();
        $this->assertSame(500500.0, (float) $po->total);
        $this->assertTrue($po->needsApproval());

        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('error', 'approval_required');
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
        Mail::assertNotQueued(PurchaseOrderEmail::class);
    }

    public function test_rest_tax_only_update_recomputes_the_total(): void
    {
        $po = $this->draftPo(500);

        Sanctum::actingAs($this->requester);
        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['tax' => 700])->assertOk();

        $this->assertSame(1200.0, (float) $po->fresh()->total);
        $this->assertTrue($po->fresh()->awaitsApproval());
    }

    public function test_graphql_shipping_only_update_recomputes_the_total_and_blocks_send(): void
    {
        $po = $this->draftPo(500);

        Sanctum::actingAs($this->requester, ['*']);
        $this->postJson('/graphql', ['query' => "mutation { updatePurchaseOrder(id: {$po->id}, shipping: 500000) { id } }"])
            ->assertJsonMissingPath('errors');

        $po->refresh();
        $this->assertSame(500500.0, (float) $po->total);
        $this->assertFalse($po->canBeSent());
    }

    public function test_raising_shipping_on_an_approved_po_clears_the_approval(): void
    {
        $po = $this->draftPo(1500);
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));
        $this->actingAs($this->approver)->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]));
        $this->assertSame(PurchaseOrder::APPROVAL_APPROVED, $po->fresh()->approval_status);

        Sanctum::actingAs($this->requester);
        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['shipping' => 500000])->assertOk();

        $po->refresh();
        $this->assertNull($po->approval_status);
        $this->assertFalse($po->canBeSent());
    }

    public function test_a_null_shipping_is_treated_as_zero_not_stored_as_null(): void
    {
        $po = $this->draftPo(500);
        $po->update(['shipping' => 40, 'total' => 540]);

        Sanctum::actingAs($this->requester);
        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['shipping' => null])->assertOk();

        $po->refresh();
        $this->assertSame(0.0, (float) $po->shipping);
        $this->assertSame(500.0, (float) $po->total);
    }
}
