<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DocumentEmailException;
use App\Mail\OrderInvoiceEmail;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\Documents\DocumentRecipients;
use App\Services\Documents\OrderInvoiceNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails an order's invoice to the customer: makes sure the invoice has its
 * number, queues the email with the invoice PDF attached, stamps when and to
 * whom it went, and records the send in the activity log. Re-sending is
 * allowed; each send updates the stamp and adds a log entry.
 */
class OrderInvoiceEmailService
{
    public function __construct(private readonly OrderInvoiceNumberService $invoiceNumbers) {}

    /**
     * @param  string|null  $to  Recipient override; defaults to the order's customer email.
     * @param  array<int, string>  $cc
     *
     * @throws DocumentEmailException when there is no valid recipient
     */
    public function send(
        Order $order,
        User $actor,
        ?string $to = null,
        array $cc = [],
        ?string $message = null,
    ): Order {
        $order->loadMissing('customer');

        $recipient = DocumentRecipients::primary(
            $to,
            $order->customer_email ?: $order->customer?->email,
            "Order #{$order->order_number} has no customer email address. Add one to the order, or enter a recipient, before emailing the invoice."
        );
        $cc = DocumentRecipients::cc($cc, $recipient);
        $message = DocumentRecipients::message($message);

        // Numbering is idempotent and happens once, outside the send: the
        // invoice keeps its number even if this particular send fails.
        $invoiceNumber = $this->invoiceNumbers->ensureAssigned($order);

        $sentAt = now();

        try {
            // Queue push last, so a failure to queue rolls back the stamp and the log.
            DB::transaction(function () use ($order, $actor, $recipient, $cc, $message, $sentAt, $invoiceNumber) {
                $order->invoice_sent_at = $sentAt;
                $order->invoice_sent_to = $recipient;
                $order->save();

                DocumentRecipients::logSend(
                    $order,
                    $actor,
                    'invoice_emailed',
                    "Emailed invoice {$invoiceNumber} for order #{$order->order_number} to {$recipient}",
                    $recipient,
                    $cc,
                    $sentAt,
                );

                Mail::to($recipient)->cc($cc)->queue(new OrderInvoiceEmail($order, $message));
            });
        } catch (Throwable $e) {
            $order->refresh();

            throw $e;
        }

        return $order;
    }
}
