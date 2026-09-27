<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\ActivityLog;
use App\Models\Inventory\StockTransfer;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StockTransferApprovalTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();
        Mail::fake();
    }

    private function createTransfer(): StockTransfer
    {
        $this->actingAs($this->requester)->post(route('stock-transfers.store'), [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        return StockTransfer::latest('id')->firstOrFail();
    }

    public function test_transfers_need_no_approval_by_default(): void
    {
        $transfer = $this->createTransfer();
        $this->assertNull($transfer->approval_status);

        $this->actingAs($this->requester)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertSessionHas('success');

        $this->assertSame('completed', $transfer->fresh()->status);
    }

    public function test_a_new_transfer_waits_for_approval_and_cannot_ship_or_complete(): void
    {
        $this->enableApprovals(['stock_transfers_enabled' => true]);
        $transfer = $this->createTransfer();

        $this->assertSame('pending', $transfer->approval_status);
        $this->assertSame($this->requester->id, $transfer->approval_requested_by);
        $this->assertTrue(Notification::where('user_id', $this->approver->id)->where('type', 'approval_requested')->exists());
        $this->assertTrue(ActivityLog::where('subject_type', StockTransfer::class)->where('action', 'approval_requested')->exists());

        $this->actingAs($this->requester)
            ->put(route('stock-transfers.update', $transfer), ['status' => 'in_transit'])
            ->assertSessionHas('error');
        $this->assertSame('pending', $transfer->fresh()->status);

        $this->actingAs($this->requester)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertSessionHas('error');
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->completed_at);
    }

    public function test_an_approved_transfer_can_be_completed(): void
    {
        $this->enableApprovals(['stock_transfers_enabled' => true]);
        $transfer = $this->createTransfer();

        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'stock_transfer', 'id' => $transfer->id]))
            ->assertSessionHas('success');
        $this->assertSame('approved', $transfer->fresh()->approval_status);
        $this->assertTrue(Notification::where('user_id', $this->requester->id)->where('type', 'approval_approved')->exists());

        $this->actingAs($this->requester)
            ->post(route('stock-transfers.complete', $transfer))
            ->assertSessionHas('success');
        $this->assertSame('completed', $transfer->fresh()->status);
    }

    public function test_a_rejected_transfer_is_cancelled(): void
    {
        $this->enableApprovals(['stock_transfers_enabled' => true]);
        $transfer = $this->createTransfer();

        $this->actingAs($this->approver)
            ->post(route('approvals.reject', ['type' => 'stock_transfer', 'id' => $transfer->id]), ['notes' => 'Aisle B is full'])
            ->assertSessionHas('success');

        $transfer->refresh();
        $this->assertSame('rejected', $transfer->approval_status);
        $this->assertSame('cancelled', $transfer->status);
        $this->assertSame('Aisle B is full', $transfer->approval_notes);
    }

    public function test_self_approval_and_permission_are_enforced(): void
    {
        $this->enableApprovals(['stock_transfers_enabled' => true]);

        $this->actingAs($this->approver)->post(route('stock-transfers.store'), [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
        $own = StockTransfer::latest('id')->firstOrFail();

        $this->actingAs($this->approver)
            ->post(route('approvals.approve', ['type' => 'stock_transfer', 'id' => $own->id]))
            ->assertSessionHas('error');
        $this->assertSame('pending', $own->fresh()->approval_status);

        $this->actingAs($this->requester)
            ->post(route('approvals.approve', ['type' => 'stock_transfer', 'id' => $own->id]))
            ->assertForbidden();
    }
}
