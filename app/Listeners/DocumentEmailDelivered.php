<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\OrderInvoiceEmail;
use App\Mail\PurchaseOrderEmail;
use App\Mail\ShipmentShippedEmail;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Shipping\Shipment;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Stamps a document's "sent" time when its email is actually delivered.
 *
 * Sending a purchase order, an invoice or a shipment notice only queues the
 * email and records queued_at. On an install whose queue worker is not
 * running the email never leaves, so the sent stamp is written here, from
 * MessageSent, which fires in whichever process delivers the message.
 *
 * Registered explicitly in AppServiceProvider; the method is deliberately not
 * named handle() so event discovery does not register it a second time.
 */
class DocumentEmailDelivered
{
    public function record(MessageSent $event): void
    {
        $mailable = $event->data['__laravel_mailable'] ?? null;

        try {
            match ($mailable) {
                PurchaseOrderEmail::class => $this->stamp($event->data['purchaseOrder'] ?? null, PurchaseOrder::class, 'sent_at'),
                OrderInvoiceEmail::class => $this->stamp($event->data['order'] ?? null, Order::class, 'invoice_sent_at'),
                ShipmentShippedEmail::class => $this->stamp($event->data['shipment'] ?? null, Shipment::class, 'customer_notified_at'),
                default => null,
            };
        } catch (\Throwable $e) {
            // The email has already gone; failing here would only make the
            // queue retry it and send a duplicate.
            Log::warning('Could not record email delivery', ['mailable' => $mailable, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     */
    private function stamp(mixed $model, string $class, string $column): void
    {
        if (! $model instanceof $class || $model->getKey() === null) {
            return;
        }

        // Queue workers have no signed-in user, so bypass the tenant scope and
        // address the row by key. A plain update keeps model events (and the
        // webhooks hanging off them) out of a delivery receipt.
        $class::withoutGlobalScopes()->whereKey($model->getKey())->toBase()->update([$column => now()]);
    }
}
