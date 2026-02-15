<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable for sending order status update emails.
 *
 * Used to notify customers or staff when an order's status has changed.
 */
class OrderStatusEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param array $data The notification data containing order information
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
        return $this->subject('Order Status Updated - #' . ($this->data['order']?->order_number ?? 'N/A'))
            ->view('emails.order-status')
            ->with($this->data);
    }
}
