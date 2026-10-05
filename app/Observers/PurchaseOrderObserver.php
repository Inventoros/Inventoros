<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Purchasing\PurchaseOrder;
use App\Services\Hooks\DomainHooks;
use Illuminate\Support\Facades\DB;

/**
 * Fires purchase-order lifecycle action hooks on the model lifecycle so
 * webhook subscribers observe them regardless of surface (web or REST),
 * and only once the surrounding transaction commits.
 */
final class PurchaseOrderObserver
{
    /**
     * Attributes whose change alone is not an edit.
     */
    private const NOT_AN_EDIT = ['updated_at', 'created_at', 'deleted_at'];

    public function __construct(private readonly DomainHooks $hooks) {}

    /**
     * Handle the PurchaseOrder "created" event.
     */
    public function created(PurchaseOrder $purchaseOrder): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('purchase_order_created', $purchaseOrder, fn () => do_action('purchase_order_created', $purchaseOrder, $user));
    }

    /**
     * Fires `purchase_order_deleted` from every surface, after the delete
     * commits.
     */
    public function deleted(PurchaseOrder $purchaseOrder): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('purchase_order_deleted', $purchaseOrder, fn () => do_action('purchase_order_deleted', $purchaseOrder, $user));
    }

    /**
     * Handle the PurchaseOrder "updated" event.
     *
     * Receiving (full or partial) and cancellation are the only status
     * transitions that map to advertised webhook events.
     */
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        // Any edit (header fields, totals after its lines change, status) is
        // one purchase_order_updated per transaction. A purchase order created
        // in the same transaction is announced by purchase_order_created alone.
        if (array_diff(array_keys($purchaseOrder->getChanges()), self::NOT_AN_EDIT) !== []
            && ! $this->hooks->isPending('purchase_order_created', $purchaseOrder)) {
            $user = auth()->user();

            $this->hooks->afterCommit('purchase_order_updated', $purchaseOrder, fn () => do_action('purchase_order_updated', $purchaseOrder, $user));
        }

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
