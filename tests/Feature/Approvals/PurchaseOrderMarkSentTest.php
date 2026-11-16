<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Exceptions\DocumentEmailException;
use App\Mail\PurchaseOrderEmail;
use App\Mcp\Tools\MarkPurchaseOrderSentTool;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #417: a purchase order placed with the supplier some other way (their
 * website, a phone call) can be marked sent without emailing anyone, so it can
 * be received. Same permission and approval gate as Send to Supplier.
 */
class PurchaseOrderMarkSentTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
    }

    private function foreignPo(): PurchaseOrder
    {
        $other = Organization::create(['name' => 'Other Org', 'email' => 'other@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $supplier = Supplier::create(['organization_id' => $other->id, 'name' => 'Other Supplier', 'is_active' => true]);

        return PurchaseOrder::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'OTHER-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'total' => 10,
            'currency' => 'USD',
        ]);
    }

    // ==================== WEB ====================

    public function test_a_draft_is_marked_sent_without_an_email_and_can_then_be_received(): void
    {
        $po = $this->draftPo();
        $item = $po->items()->sole();
        $stock = (int) $this->product->fresh()->stock;

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.mark-sent', $po))
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('success', 'Purchase order marked as sent.');

        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->status);
        $this->assertTrue($po->canReceiveItems());
        // Nothing was emailed, so the email fields stay empty.
        $this->assertNull($po->sent_to);
        $this->assertNull($po->queued_at);
        $this->assertNull($po->sent_at);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        Sanctum::actingAs($this->requester);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => [['id' => $item->id, 'quantity_to_receive' => 10]],
        ])->assertOk();

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);
        $this->assertSame($stock + 10, (int) $this->product->fresh()->stock);
    }

    public function test_marking_sent_is_recorded_in_the_activity_log(): void
    {
        $po = $this->draftPo();

        $this->actingAs($this->requester)->post(route('purchase-orders.mark-sent', $po));

        $log = ActivityLog::where('subject_type', PurchaseOrder::class)
            ->where('subject_id', $po->id)
            ->where('action', 'marked_sent')
            ->sole();

        $this->assertSame($this->org->id, $log->organization_id);
        $this->assertSame($this->requester->id, $log->user_id);
        $this->assertStringContainsString($po->po_number, $log->description);
        $this->assertFalse($log->properties['emailed']);
        $this->assertSame('sent', $log->properties['to_status']);
    }

    public function test_a_user_without_edit_permission_cannot_mark_sent(): void
    {
        $po = $this->draftPo();

        $this->actingAs($this->bystander)
            ->post(route('purchase-orders.mark-sent', $po))
            ->assertForbidden();

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', 'marked_sent')->count());
    }

    public function test_a_draft_that_needs_approval_cannot_skip_it(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.mark-sent', $po))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'needs approval'));
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);

        // Pending: still blocked.
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));
        $this->actingAs($this->requester)
            ->post(route('purchase-orders.mark-sent', $po))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'waiting for approval'));
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);

        // Approved: allowed.
        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrder::APPROVAL_APPROVED, $po->fresh()->approval_status);

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.mark-sent', $po))
            ->assertSessionHas('success');
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
    }

    public function test_a_po_that_is_not_a_draft_is_refused(): void
    {
        foreach ([PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELLED] as $status) {
            $po = $this->draftPo();
            $po->forceFill(['status' => $status])->save();

            $this->actingAs($this->requester)
                ->post(route('purchase-orders.mark-sent', $po))
                ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Only a draft can be marked as sent'));

            $this->assertSame($status, $po->fresh()->status);
        }

        $this->assertSame(0, ActivityLog::where('action', 'marked_sent')->count());
    }

    public function test_a_draft_without_items_is_refused(): void
    {
        $po = $this->draftPo();
        $po->items()->delete();

        $this->actingAs($this->requester)
            ->post(route('purchase-orders.mark-sent', $po))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'no items'));

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
    }

    public function test_a_second_submit_is_refused_and_logged_once(): void
    {
        $po = $this->draftPo();

        $this->actingAs($this->requester)->post(route('purchase-orders.mark-sent', $po))->assertSessionHas('success');
        $this->actingAs($this->requester)->post(route('purchase-orders.mark-sent', $po))->assertSessionHas('error');

        $this->assertSame(1, ActivityLog::where('action', 'marked_sent')->count());
    }

    public function test_a_stale_copy_is_rechecked_under_the_lock(): void
    {
        // A copy loaded before the PO was sent elsewhere must not mark it
        // again (or log a second time).
        $po = $this->draftPo();
        $stale = PurchaseOrder::find($po->id);
        PurchaseOrder::whereKey($po->id)->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        try {
            app(PurchaseOrderEmailService::class)->markSent($stale, $this->requester);
            $this->fail('A cancelled purchase order was marked sent.');
        } catch (DocumentEmailException $e) {
            $this->assertSame(DocumentEmailException::NOT_SENDABLE, $e->reason);
        }

        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $po->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', 'marked_sent')->count());
    }

    public function test_another_organizations_po_cannot_be_marked_sent(): void
    {
        $foreign = $this->foreignPo();

        $response = $this->actingAs($this->requester)->post(route('purchase-orders.mark-sent', $foreign->id));
        $this->assertContains($response->status(), [403, 404]);

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, PurchaseOrder::withoutGlobalScopes()->find($foreign->id)->status);
    }

    // ==================== REST API ====================

    public function test_api_marks_a_draft_sent_without_an_email(): void
    {
        Sanctum::actingAs($this->requester);
        $po = $this->draftPo();

        $this->postJson("/api/v1/purchase-orders/{$po->id}/mark-sent")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.sent_to', null);

        Mail::assertNotQueued(PurchaseOrderEmail::class);
        $this->assertSame(1, ActivityLog::where('action', 'marked_sent')->count());
    }

    public function test_api_refuses_an_unapproved_draft_a_sent_po_and_a_missing_permission(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();

        Sanctum::actingAs($this->requester);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/mark-sent")
            ->assertStatus(422)
            ->assertJsonPath('error', 'approval_required');

        $sent = $this->draftPo();
        $sent->forceFill(['status' => PurchaseOrder::STATUS_SENT])->save();
        $this->postJson("/api/v1/purchase-orders/{$sent->id}/mark-sent")
            ->assertStatus(422)
            ->assertJsonPath('error', 'cannot_send');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->bystander);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/mark-sent")->assertForbidden();

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
    }

    public function test_api_returns_404_for_another_organizations_po(): void
    {
        Sanctum::actingAs($this->requester);
        $foreign = $this->foreignPo();

        $this->postJson("/api/v1/purchase-orders/{$foreign->id}/mark-sent")->assertNotFound();

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, PurchaseOrder::withoutGlobalScopes()->find($foreign->id)->status);
    }

    // ==================== MCP ====================

    public function test_mcp_marks_a_draft_sent_and_respects_approval_and_permissions(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true]);
        $po = $this->draftPo();

        $this->mcpAs($this->requester)
            ->tool(MarkPurchaseOrderSentTool::class, ['id' => $po->id])
            ->assertHasErrors(['approval']);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);

        $this->mcpAs($this->bystander)
            ->tool(MarkPurchaseOrderSentTool::class, ['id' => $po->id])
            ->assertHasErrors();

        $this->enableApprovals(['purchase_orders_enabled' => false]);

        $this->mcpAs($this->requester)
            ->tool(MarkPurchaseOrderSentTool::class, ['id' => $po->id])
            ->assertOk()
            ->assertSee('No email was sent');

        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_mcp_cannot_reach_another_organizations_po(): void
    {
        $foreign = $this->foreignPo();

        $this->mcpAs($this->requester)
            ->tool(MarkPurchaseOrderSentTool::class, ['id' => $foreign->id])
            ->assertHasErrors(['not found']);

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, PurchaseOrder::withoutGlobalScopes()->find($foreign->id)->status);
    }
}
