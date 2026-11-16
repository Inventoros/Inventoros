<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DocumentEmailException;
use App\Mail\PurchaseOrderEmail;
use App\Models\ActivityLog;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\Documents\DocumentEmailThrottle;
use App\Services\Documents\DocumentRecipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a purchase order to its supplier: queues an email with the PO PDF
 * attached, moves a draft to "sent", stamps when it was queued and to whom,
 * and records the send in the activity log. sent_at is stamped later, when
 * the queue worker actually delivers the email (DocumentEmailDelivered). The web UI, the REST API and the MCP
 * tool all go through here so the three behave identically.
 *
 * A draft can also be marked sent without an email (markSent()), for orders
 * placed with the supplier some other way, such as on the supplier's own
 * ordering site. It passes the same approval gate as an emailed send.
 */
class PurchaseOrderEmailService
{
    /**
     * @param  string|null  $to  Recipient override; defaults to the supplier's email.
     * @param  array<int, string>  $cc
     *
     * @throws DocumentEmailException when the PO cannot be sent or has no valid recipient
     */
    public function send(
        PurchaseOrder $purchaseOrder,
        User $actor,
        ?string $to = null,
        array $cc = [],
        ?string $message = null,
    ): PurchaseOrder {
        $this->assertSendable($purchaseOrder);

        $purchaseOrder->loadMissing('supplier');
        $supplierName = $purchaseOrder->supplier?->name ?? 'The supplier';

        $recipient = DocumentRecipients::primary(
            $to,
            $purchaseOrder->supplier?->email,
            "{$supplierName} has no email address. Add one to the supplier, or enter a recipient, before sending this purchase order."
        );
        $cc = DocumentRecipients::cc($cc, $recipient);
        $message = DocumentRecipients::message($message);

        DocumentEmailThrottle::hit($actor, 'purchase_order');

        $sentAt = now();

        // Everything happens in one transaction with the queue push last: if
        // the mail cannot be queued the status change and the log roll back,
        // so a PO is only marked sent when its email is actually on its way.
        try {
            $this->sendInTransaction($purchaseOrder, $actor, $recipient, $cc, $message, $sentAt);
        } catch (Throwable $e) {
            // Drop the in-memory status/sent_at the rolled-back save left behind.
            $purchaseOrder->refresh();

            throw $e;
        }

        return $purchaseOrder;
    }

    /**
     * @param  array<int, string>  $cc
     */
    private function sendInTransaction(
        PurchaseOrder $purchaseOrder,
        User $actor,
        string $recipient,
        array $cc,
        ?string $message,
        Carbon $sentAt,
    ): void {
        DB::transaction(function () use ($purchaseOrder, $actor, $recipient, $cc, $message, $sentAt) {
            // Re-check on the row as it is now, under the lock an edit also
            // takes: a copy loaded before a concurrent edit must not send
            // terms that were changed (and so need approval again).
            $locked = PurchaseOrder::withoutGlobalScope(OrganizationScope::class)
                ->whereKey($purchaseOrder->getKey())
                ->where('organization_id', $purchaseOrder->organization_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertSendable($locked);

            if ($locked->status === PurchaseOrder::STATUS_DRAFT) {
                $locked->status = PurchaseOrder::STATUS_SENT;
            }
            $locked->queued_at = $sentAt;
            $locked->sent_to = $recipient;
            $locked->save();
            $purchaseOrder->setRawAttributes($locked->getAttributes(), true);

            DocumentRecipients::logSend(
                $purchaseOrder,
                $actor,
                'emailed',
                "Emailed purchase order {$purchaseOrder->po_number} to {$recipient}",
                $recipient,
                $cc,
                $sentAt,
            );

            Mail::to($recipient)->cc($cc)->queue(new PurchaseOrderEmail($purchaseOrder, $message));
        });
    }

    /**
     * Mark a draft purchase order as sent without emailing anyone, so it can
     * be received. The order was placed with the supplier some other way.
     *
     * Only a draft with at least one item that does not need (or already
     * has) approval can be marked sent: the same rules as a first emailed
     * send. The row is re-checked under the lock an edit and an emailed send
     * also take, so a double submit or a concurrent change cannot slip past
     * them. Nothing is queued and sent_to, queued_at and sent_at are left
     * alone; the activity log records who marked it.
     *
     * @throws DocumentEmailException when the PO awaits approval, is not a
     *                                draft, or has no items
     */
    public function markSent(PurchaseOrder $purchaseOrder, User $actor): PurchaseOrder
    {
        $this->assertMarkableSent($purchaseOrder);

        try {
            DB::transaction(function () use ($purchaseOrder, $actor) {
                $locked = PurchaseOrder::withoutGlobalScope(OrganizationScope::class)
                    ->whereKey($purchaseOrder->getKey())
                    ->where('organization_id', $purchaseOrder->organization_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertMarkableSent($locked);

                $locked->status = PurchaseOrder::STATUS_SENT;
                $locked->save();
                $purchaseOrder->setRawAttributes($locked->getAttributes(), true);

                ActivityLog::create([
                    'organization_id' => $purchaseOrder->organization_id,
                    'user_id' => $actor->id,
                    'subject_type' => $purchaseOrder::class,
                    'subject_id' => $purchaseOrder->getKey(),
                    'action' => 'marked_sent',
                    'description' => "Marked purchase order {$purchaseOrder->po_number} as sent without emailing the supplier",
                    'properties' => [
                        'from_status' => PurchaseOrder::STATUS_DRAFT,
                        'to_status' => PurchaseOrder::STATUS_SENT,
                        'emailed' => false,
                        'marked_by' => $actor->name,
                    ],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
            });
        } catch (Throwable $e) {
            $purchaseOrder->refresh();

            throw $e;
        }

        return $purchaseOrder;
    }

    /**
     * @throws DocumentEmailException when the PO cannot be marked sent
     */
    private function assertMarkableSent(PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            throw DocumentEmailException::notSendable(
                "Purchase order {$purchaseOrder->po_number} is {$purchaseOrder->status_label}. Only a draft can be marked as sent."
            );
        }

        $this->assertApproved($purchaseOrder);

        if (! $purchaseOrder->canBeSent()) {
            throw DocumentEmailException::notSendable(
                "Purchase order {$purchaseOrder->po_number} has no items. Add at least one item before marking it as sent."
            );
        }
    }

    /**
     * @throws DocumentEmailException when the PO awaits approval or cannot be sent
     */
    private function assertSendable(PurchaseOrder $purchaseOrder): void
    {
        $this->assertApproved($purchaseOrder);

        if (! $purchaseOrder->canBeSent()) {
            throw DocumentEmailException::notSendable(
                "Purchase order {$purchaseOrder->po_number} is {$purchaseOrder->status_label} and cannot be sent. Only drafts with at least one item, or orders already sent, can be sent."
            );
        }
    }

    /**
     * @throws DocumentEmailException when the organization's approval rules
     *                                cover this draft and it is not approved
     */
    private function assertApproved(PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder->awaitsApproval()) {
            throw DocumentEmailException::approvalRequired(match ($purchaseOrder->approval_status) {
                PurchaseOrder::APPROVAL_PENDING => "Purchase order {$purchaseOrder->po_number} is waiting for approval and cannot be sent yet.",
                PurchaseOrder::APPROVAL_REJECTED => "Purchase order {$purchaseOrder->po_number} was rejected. Update it and submit it for approval again before sending.",
                default => "Purchase order {$purchaseOrder->po_number} needs approval before it can be sent. Submit it for approval first.",
            });
        }
    }
}
