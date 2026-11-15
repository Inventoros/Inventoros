<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Exceptions\InvalidStateException;
use App\Models\Auth\Organization;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cancelling a purchase order decides on the row as it is when it writes.
 * SQLite runs tests on one connection, so a concurrent receive is
 * reproduced deterministically: a stale instance is loaded, goods are
 * received against the row, then the stale instance is cancelled.
 */
class PurchaseOrderCancelLockTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpApprovalWorld();
    }

    private function sentPo(): PurchaseOrder
    {
        $po = $this->draftPo(500);
        $po->update(['status' => PurchaseOrder::STATUS_SENT]);

        return $po->fresh();
    }

    private function receive(PurchaseOrder $po, int $quantity): void
    {
        $line = $po->items()->firstOrFail();
        // The stock ledger records the signed-in user.
        $this->actingAs($this->requester);
        app(PurchaseOrderService::class)->receive(PurchaseOrder::findOrFail($po->id), $this->requester, [
            ['id' => $line->id, 'quantity_to_receive' => $quantity],
        ]);
    }

    public function test_a_stale_cancel_cannot_overwrite_a_partial_receipt(): void
    {
        $po = $this->sentPo();
        $stale = PurchaseOrder::findOrFail($po->id);
        $this->assertTrue($stale->canBeCancelled());

        $this->receive($po, 4);
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $po->fresh()->status);

        try {
            $stale->cancel();
            $this->fail('Expected the cancel to be refused');
        } catch (InvalidStateException $error) {
            $this->assertSame('cannot_cancel', $error->errorCode);
        }

        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $po->fresh()->status);
        $this->assertSame(104, (int) $this->product->fresh()->stock);
    }

    public function test_a_stale_cancel_cannot_overwrite_a_full_receipt(): void
    {
        $po = $this->sentPo();
        $stale = PurchaseOrder::findOrFail($po->id);

        $this->receive($po, 10);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);

        $this->expectException(InvalidStateException::class);
        try {
            $stale->cancel();
        } finally {
            $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);
        }
    }

    public function test_a_cancelled_po_cannot_then_receive(): void
    {
        $po = $this->sentPo();
        $po->cancel();

        $this->expectException(InvalidStateException::class);
        try {
            $this->receive($po, 4);
        } finally {
            $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $po->fresh()->status);
            $this->assertSame(100, (int) $this->product->fresh()->stock);
        }
    }

    public function test_web_and_api_still_cancel_a_sent_or_draft_po(): void
    {
        $sent = $this->sentPo();
        $this->actingAs($this->requester)->post(route('purchase-orders.cancel', $sent))
            ->assertRedirect(route('purchase-orders.show', $sent))->assertSessionHas('success');
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $sent->fresh()->status);

        $draft = $this->draftPo(300);
        Sanctum::actingAs($this->requester);
        $this->postJson("/api/v1/purchase-orders/{$draft->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', PurchaseOrder::STATUS_CANCELLED);
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $draft->fresh()->status);
    }

    public function test_web_and_api_refuse_to_cancel_a_partly_received_po(): void
    {
        $po = $this->sentPo();
        $this->receive($po, 4);

        $this->actingAs($this->requester)->post(route('purchase-orders.cancel', $po))->assertSessionHas('error');
        Sanctum::actingAs($this->requester);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel")->assertStatus(422)->assertJsonPath('error', 'cannot_cancel');
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $po->fresh()->status);
    }

    public function test_another_organizations_po_cannot_be_cancelled(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $po = $this->sentPo();
        $po->forceFill(['organization_id' => $other->id])->save();

        Sanctum::actingAs($this->requester);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel")->assertNotFound();
        $this->assertSame(PurchaseOrder::STATUS_SENT, PurchaseOrder::withoutGlobalScopes()->findOrFail($po->id)->status);
    }
}
