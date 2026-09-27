<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DocumentEmailException;
use App\Mail\PurchaseOrderEmail;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Services\Documents\DocumentRecipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a purchase order to its supplier: queues an email with the PO PDF
 * attached, moves a draft to "sent", stamps when and to whom it went, and
 * records the send in the activity log. The web UI, the REST API and the MCP
 * tool all go through here so the three behave identically.
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
        if (! $purchaseOrder->canBeSent()) {
            throw DocumentEmailException::notSendable(
                "Purchase order {$purchaseOrder->po_number} is {$purchaseOrder->status_label} and cannot be sent. Only drafts with at least one item, or orders already sent, can be sent."
            );
        }

        $purchaseOrder->loadMissing('supplier');
        $supplierName = $purchaseOrder->supplier?->name ?? 'The supplier';

        $recipient = DocumentRecipients::primary(
            $to,
            $purchaseOrder->supplier?->email,
            "{$supplierName} has no email address. Add one to the supplier, or enter a recipient, before sending this purchase order."
        );
        $cc = DocumentRecipients::cc($cc, $recipient);
        $message = DocumentRecipients::message($message);

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
            if ($purchaseOrder->status === PurchaseOrder::STATUS_DRAFT) {
                $purchaseOrder->status = PurchaseOrder::STATUS_SENT;
            }
            $purchaseOrder->sent_at = $sentAt;
            $purchaseOrder->sent_to = $recipient;
            $purchaseOrder->save();

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
}
