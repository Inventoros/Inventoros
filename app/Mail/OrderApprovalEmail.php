<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Mailable for sending order approval notification emails.
 *
 * Used to notify users when an order has been approved or rejected.
 */
class OrderApprovalEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param array $data The notification data containing order and approval information
     */
    public function __construct(public array $data)
    {
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $status = $this->data['order']?->approval_status ?? 'pending';

        return $this->subject('Order ' . Str::title(str_replace('_', ' ', $status)) . ' - #' . ($this->data['order']?->order_number ?? 'N/A'))
            ->view('emails.order-approval')
            ->with($this->data);
    }
}
