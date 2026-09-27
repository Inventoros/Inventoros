<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Mail\Concerns\UsesOrganizationBranding;
use BackedEnum;
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
    use AppliesOrganizationMailConfig, Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Create a new message instance.
     *
     * @param  array  $data  The notification data containing order information
     */
    public function __construct(public array $data) {}

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $this->applyOrganizationMailConfig();

        return $this->subject('Order Status Updated - #'.($this->data['order']?->order_number ?? 'N/A'))
            ->view('emails.order-status')
            ->text('emails.text.order-status')
            ->with($this->data + $this->organizationBranding() + [
                // Order status is an enum; the templates want the plain value.
                'statusValue' => self::plain($this->data['order']?->status ?? null),
                'oldStatusValue' => self::plain($this->data['old_status'] ?? null),
            ]);
    }

    private static function plain(mixed $status): ?string
    {
        return $status instanceof BackedEnum ? (string) $status->value : ($status === null ? null : (string) $status);
    }
}
