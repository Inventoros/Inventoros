<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable for sending low stock alert emails.
 *
 * Used to notify staff when product inventory falls below minimum levels.
 */
class LowStockEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param array $data The notification data containing product information
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
        return $this->subject('Low Stock Alert - ' . ($this->data['product']?->name ?? 'Unknown Product'))
            ->view('emails.low-stock-alert')
            ->with($this->data);
    }
}
