<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Mail\ApprovalEmail;
use App\Mail\PurchaseOrderEmail;
use App\Mcp\Tools\DecideApprovalTool;
use App\Mcp\Tools\SendPurchaseOrderTool;
use App\Mcp\Tools\SubmitPurchaseOrderForApprovalTool;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseOrderApprovalTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
    }

    // ==================== DEFAULT OFF ====================

    public function test_approvals_are_off_by_default_so_a_draft_sends_as_before(): void
    {
        Mail::fake();
        $po = $this->draftPo();

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.send', $po))
            ->assertSessionHas('success');

        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
        $this->assertNull($po->fresh()->approval_status);
    }

    // ==================== GATE ====================

    public function test_an_unapproved_draft_cannot_be_sent_when_approvals_are_on(): void
    {
        Mail::fake();
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.send', $po))
            ->assertSessionHas('error');

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
        Mail::assertNotQueued(PurchaseOrderEmail::class);
        $this->assertFalse($po->fresh()->canBeSent());
    }

    public function test_a_po_below_the_threshold_needs_no_approval(): void
    {
        Mail::fake();
        $this->enableApprovals(['purchase_orders_enabled' => true, 'purchase_orders_threshold' => 1000]);
        $po = $this->draftPo(500);

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.send', $po))
            ->assertSessionHas('success');

        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
    }

    public function test_a_po_at_or_above_the_threshold_needs_approval(): void
    {
        Mail::fake();
        $this->enableApprovals(['purchase_orders_enabled' => true, 'purchase_orders_threshold' => 500]);
        $po = $this->draftPo(500);

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.send', $po))
            ->assertSessionHas('error');

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
    }

    // ==================== SUBMIT ====================

    public function test_submitting_puts_the_po_in_pending_approval_and_notifies_approvers(): void
    {
        Mail::fake();
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.submit-approval', $po))
            ->assertSessionHas('success');

        $po->refresh();
        $this->assertSame('pending', $po->approval_status);
        $this->assertSame($this->requester->id, $po->approval_requested_by);
        $this->assertFalse($po->canBeEdited(), 'A PO awaiting approval is locked for editing.');

        // Approver and admin are told; the requester and bystander are not.
        $notified = Notification::where('type', 'approval_requested')->pluck('user_id')->all();
        sort($notified);
        $expected = [$this->admin->id, $this->approver->id];
        sort($expected);
        $this->assertSame($expected, $notified);

        Mail::assertQueued(ApprovalEmail::class, fn (ApprovalEmail $m) => $m->hasTo('abe@acme.test'));
        Mail::assertNotQueued(ApprovalEmail::class, fn (ApprovalEmail $m) => $m->hasTo('bo@acme.test'));

        $this->assertTrue(ActivityLog::where('subject_type', PurchaseOrder::class)
            ->where('subject_id', $po->id)->where('action', 'approval_requested')->exists());
    }

    public function test_submitting_a_po_that_needs_no_approval_is_refused(): void
    {
        $po = $this->draftPo();

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.submit-approval', $po))
            ->assertSessionHas('error');

        $this->assertNull($po->fresh()->approval_status);
    }

    // ==================== DECIDE ====================

    public function test_an_approver_approves_and_the_po_can_then_be_sent(): void
    {
        Mail::fake();
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));

        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]), ['notes' => 'Budget ok'])
            ->assertSessionHas('success');

        $po->refresh();
        $this->assertSame('approved', $po->approval_status);
        $this->assertSame($this->approver->id, $po->approved_by);
        $this->assertSame('Budget ok', $po->approval_notes);

        $this->assertTrue(Notification::where('user_id', $this->requester->id)->where('type', 'approval_approved')->exists());
        Mail::assertQueued(ApprovalEmail::class, fn (ApprovalEmail $m) => $m->hasTo('rae@acme.test'));

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.send', $po))
            ->assertSessionHas('success');
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
    }

    public function test_rejection_needs_a_reason_and_keeps_the_po_unsendable(): void
    {
        Mail::fake();
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));

        $this->actingAs($this->approver)
            ->post(route('approvals.reject', ['type' => 'purchase_order', 'id' => $po->id]), [])
            ->assertSessionHasErrors('notes');
        $this->assertSame('pending', $po->fresh()->approval_status);

        $this->actingAs($this->approver)
            ->post(route('approvals.reject', ['type' => 'purchase_order', 'id' => $po->id]), ['notes' => 'Wrong supplier'])
            ->assertSessionHas('success');

        $po->refresh();
        $this->assertSame('rejected', $po->approval_status);
        $this->assertSame('Wrong supplier', $po->approval_notes);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->status);
        $this->assertFalse($po->canBeSent());
        $this->assertTrue($po->canBeEdited(), 'A rejected PO goes back to the requester to fix.');
        $this->assertTrue(Notification::where('user_id', $this->requester->id)->where('type', 'approval_rejected')->exists());
    }

    public function test_a_user_without_the_permission_cannot_approve(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));

        $this->actingAs($this->bystander)
            ->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]))
            ->assertForbidden();

        $this->assertSame('pending', $po->fresh()->approval_status);
    }

    public function test_an_approver_cannot_approve_their_own_request(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo(500, $this->approver);
        $this->actingAs($this->approver)->post(route('purchase-orders.submit-approval', $po));

        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]))
            ->assertSessionHas('error');

        $this->assertSame('pending', $po->fresh()->approval_status);
    }

    public function test_an_admin_can_approve_their_own_request_unless_turned_off(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo(500, $this->admin);
        $this->actingAs($this->admin)->post(route('purchase-orders.submit-approval', $po));

        $this->actingAs($this->admin)
            ->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]))
            ->assertSessionHas('success');
        $this->assertSame('approved', $po->fresh()->approval_status);

        $this->enableApprovals(['admins_can_self_approve' => false]);
        $second = $this->draftPo(500, $this->admin);
        $this->actingAs($this->admin)->post(route('purchase-orders.submit-approval', $second));

        $this->actingAs($this->admin)
            ->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $second->id]))
            ->assertSessionHas('error');
        $this->assertSame('pending', $second->fresh()->approval_status);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));
        $this->actingAs($this->approver)->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]));

        $this->actingAs($this->approver)
            ->post(route('approvals.reject', ['type' => 'purchase_order', 'id' => $po->id]), ['notes' => 'Too late'])
            ->assertSessionHas('error');

        $this->assertSame('approved', $po->fresh()->approval_status);
    }

    public function test_changing_an_approved_po_sends_it_back_for_approval(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));
        $this->actingAs($this->approver)->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]));

        $po->refresh();
        $po->update(['total' => 9000]);

        $this->assertNull($po->fresh()->approval_status);
        $this->assertFalse($po->fresh()->canBeSent());
    }

    public function test_another_organizations_po_is_not_found(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));

        $other = \App\Models\Auth\Organization::create(['name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $outsider = \App\Models\User::create([
            'name' => 'Out', 'email' => 'out@o.test', 'password' => bcrypt('x'), 'organization_id' => $other->id, 'role' => 'admin',
        ]);

        $this->actingAs($outsider)
            ->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]))
            ->assertNotFound();
    }

    // ==================== REST ====================

    public function test_api_send_is_refused_until_approved_and_the_api_can_submit_and_approve(): void
    {
        Mail::fake();
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();

        Sanctum::actingAs($this->requester);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('error', 'approval_required');

        $this->postJson("/api/v1/purchase-orders/{$po->id}/submit-for-approval")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'pending');

        Sanctum::actingAs($this->approver);
        $this->getJson('/api/v1/approvals')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'purchase_order')
            ->assertJsonPath('data.0.id', $po->id);

        $this->postJson("/api/v1/approvals/purchase_order/{$po->id}/approve", ['notes' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        Sanctum::actingAs($this->requester);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertOk();
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
    }

    public function test_api_self_approval_and_missing_permission_are_forbidden(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo(500, $this->approver);

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/submit-for-approval")->assertOk();
        $this->postJson("/api/v1/approvals/purchase_order/{$po->id}/approve")
            ->assertForbidden()
            ->assertJsonPath('error', 'self_approval');

        Sanctum::actingAs($this->bystander);
        $this->postJson("/api/v1/approvals/purchase_order/{$po->id}/approve")
            ->assertForbidden()
            ->assertJsonPath('error', 'forbidden');
    }

    // ==================== MCP ====================

    public function test_mcp_send_is_refused_until_approved(): void
    {
        Mail::fake();
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();

        $this->mcpAs($this->requester)
            ->tool(SendPurchaseOrderTool::class, ['id' => $po->id])
            ->assertHasErrors(['approval']);

        $this->mcpAs($this->requester)
            ->tool(SubmitPurchaseOrderForApprovalTool::class, ['id' => $po->id])
            ->assertOk();
        $this->assertSame('pending', $po->fresh()->approval_status);

        $this->mcpAs($this->requester)
            ->tool(DecideApprovalTool::class, ['type' => 'purchase_order', 'id' => $po->id, 'decision' => 'approve'])
            ->assertHasErrors(['permission']);

        $this->mcpAs($this->approver)
            ->tool(DecideApprovalTool::class, ['type' => 'purchase_order', 'id' => $po->id, 'decision' => 'approve'])
            ->assertOk();
        $this->assertSame('approved', $po->fresh()->approval_status);

        $this->mcpAs($this->requester)
            ->tool(SendPurchaseOrderTool::class, ['id' => $po->id])
            ->assertOk();
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
    }

    // ==================== GRAPHQL ====================

    public function test_graphql_exposes_the_approval_state_and_decides_through_the_service(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo(500, $this->approver);
        $this->actingAs($this->approver)->post(route('purchase-orders.submit-approval', $po));

        Sanctum::actingAs($this->approver, ['*']);
        $this->postJson('/graphql', ['query' => "{ purchaseOrder(id: {$po->id}) { id approval_status } }"])
            ->assertJsonPath('data.purchaseOrder.approval_status', 'pending');

        // Self-approval is refused here too.
        $this->postJson('/graphql', ['query' => "mutation { decideApproval(type: \"purchase_order\", id: {$po->id}, decision: \"approve\") { status } }"])
            ->assertJsonPath('errors.0.message', 'You cannot approve your own request.');
        $this->assertSame('pending', $po->fresh()->approval_status);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/graphql', ['query' => "mutation { decideApproval(type: \"purchase_order\", id: {$po->id}, decision: \"approve\", notes: \"fine\") { type id status } }"])
            ->assertJsonPath('data.decideApproval.status', 'approved');
        $this->assertSame('approved', $po->fresh()->approval_status);
    }
}
