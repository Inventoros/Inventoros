<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Fires purchase-order lifecycle action hooks on the model lifecycle so
 * webhook subscribers observe them regardless of surface (web or REST),
 * and only once the surrounding transaction commits.
 */
final class PurchaseOrderObserver
{
    /**
     * Handle the PurchaseOrder "created" event.
     */
    public function created(PurchaseOrder $purchaseOrder): void
    {
        DB::afterCommit(fn () => do_action('purchase_order_created', $purchaseOrder, auth()->user()));
    }

    /**
     * Handle the PurchaseOrder "updated" event.
     *
     * Receiving (full or partial) and cancellation are the only status
     * transitions that map to advertised webhook events.
     */
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        if (! $purchaseOrder->isDirty('status')) {
            return;
        }

        $status = $purchaseOrder->status;

        if (in_array($status, [PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_RECEIVED], true)) {
            DB::afterCommit(fn () => do_action('purchase_order_received', $purchaseOrder, auth()->user()));
        } elseif ($status === PurchaseOrder::STATUS_CANCELLED) {
            DB::afterCommit(fn () => do_action('purchase_order_cancelled', $purchaseOrder, auth()->user()));
        }
    }
}
