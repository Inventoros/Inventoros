<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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
        $status = $this->data['order']->approval_status;

        return $this->subject('Order ' . ucfirst($status) . ' - #' . $this->data['order']->order_number)
            ->view('emails.order-approval')
            ->with($this->data);
    }
}
