<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Exceptions\DocumentEmailException;
use App\Exceptions\InvalidStateException;
use App\Mail\PurchaseOrderEmail;
use App\Models\Auth\Organization;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\PurchaseOrderEmailService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Editing and sending a purchase order decide on the row as it is when they
 * write, not on a copy loaded earlier in the request. SQLite runs tests on a
 * single connection, so the concurrent requests are reproduced
 * deterministically: a stale instance is loaded, the row changes underneath
 * it, then the stale instance is passed to the service.
 */
class PurchaseOrderStaleEditSendTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
        $this->enableApprovals(['purchase_orders_enabled' => true, 'purchase_orders_threshold' => 1000]);
    }

    public function test_send_rechecks_approval_on_the_current_row(): void
    {
        $po = $this->draftPo(500);
        $stale = PurchaseOrder::findOrFail($po->id);
        $this->assertTrue($stale->canBeSent());

        // A concurrent edit raises the total over the approval threshold.
        app(PurchaseOrderService::class)->update(PurchaseOrder::findOrFail($po->id), $this->org->id, ['shipping' => 5000]);
        $this->assertTrue($po->fresh()->awaitsApproval());

        try {
            app(PurchaseOrderEmailService::class)->send($stale, $this->requester);
            $this->fail('Expected the approval gate');
        } catch (DocumentEmailException $error) {
            $this->assertSame(DocumentEmailException::APPROVAL_REQUIRED, $error->reason);
        }

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
        Mail::assertNotQueued(PurchaseOrderEmail::class);
    }

    public function test_send_rechecks_a_cleared_approval_on_the_current_row(): void
    {
        $po = $this->draftPo(5000);
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));
        $this->actingAs($this->approver)->post(route('approvals.approve', ['type' => 'purchase_order', 'id' => $po->id]));
        $stale = PurchaseOrder::findOrFail($po->id);
        $this->assertTrue($stale->canBeSent());

        // A concurrent edit changes the approved terms, which clears the approval.
        app(PurchaseOrderService::class)->update(PurchaseOrder::findOrFail($po->id), $this->org->id, ['shipping' => 9000]);
        $this->assertNull($po->fresh()->approval_status);

        $this->expectException(DocumentEmailException::class);
        try {
            app(PurchaseOrderEmailService::class)->send($stale, $this->requester);
        } finally {
            $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
            Mail::assertNotQueued(PurchaseOrderEmail::class);
        }
    }

    public function test_update_rechecks_editability_on_the_current_row(): void
    {
        $po = $this->draftPo(500);
        $stale = PurchaseOrder::findOrFail($po->id);
        $line = $po->items()->firstOrFail();

        // A concurrent send moves the draft to the supplier.
        app(PurchaseOrderEmailService::class)->send(PurchaseOrder::findOrFail($po->id), $this->requester);
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);

        try {
            app(PurchaseOrderService::class)->update($stale, $this->org->id, [
                'shipping' => 5000,
                'items' => [['id' => $line->id, 'product_id' => $this->product->id, 'quantity' => 500, 'unit_cost' => 50]],
            ]);
            $this->fail('Expected the edit to be refused');
        } catch (InvalidStateException $error) {
            $this->assertSame('cannot_edit', $error->errorCode);
        }

        $fresh = $po->fresh();
        $this->assertSame(500.0, (float) $fresh->total);
        $this->assertSame(0.0, (float) $fresh->shipping);
        $this->assertSame(10, (int) $line->fresh()->quantity_ordered);
    }

    public function test_update_rechecks_a_pending_approval_on_the_current_row(): void
    {
        $po = $this->draftPo(5000);
        $stale = PurchaseOrder::findOrFail($po->id);
        $this->actingAs($this->requester)->post(route('purchase-orders.submit-approval', $po));
        $this->assertSame(PurchaseOrder::APPROVAL_PENDING, $po->fresh()->approval_status);

        $this->expectException(InvalidStateException::class);
        try {
            app(PurchaseOrderService::class)->update($stale, $this->org->id, ['shipping' => 100]);
        } finally {
            $this->assertSame(0.0, (float) $po->fresh()->shipping);
            $this->assertSame(PurchaseOrder::APPROVAL_PENDING, $po->fresh()->approval_status);
        }
    }

    public function test_fresh_edit_and_send_still_work(): void
    {
        $po = $this->draftPo(500);

        $updated = app(PurchaseOrderService::class)->update($po, $this->org->id, ['shipping' => 100]);
        $this->assertSame(600.0, (float) $updated->total);

        $sent = app(PurchaseOrderEmailService::class)->send($po, $this->requester);
        $this->assertSame(PurchaseOrder::STATUS_SENT, $sent->status);
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
        Mail::assertQueued(PurchaseOrderEmail::class);
    }

    public function test_another_organizations_po_cannot_be_updated(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $po = $this->draftPo(500);
        $po->forceFill(['organization_id' => $other->id])->save();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(PurchaseOrderService::class)->update(PurchaseOrder::withoutGlobalScopes()->findOrFail($po->id), $this->org->id, ['shipping' => 100]);
    }
}
