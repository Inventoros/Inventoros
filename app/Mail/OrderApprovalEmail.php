<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Mail\Concerns\UsesOrganizationBranding;
use BackedEnum;
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
    use AppliesOrganizationMailConfig, Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Create a new message instance.
     *
     * @param  array  $data  The notification data containing order and approval information
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

        // approval_status is cast to an enum; use its plain value.
        $status = $this->data['order']?->approval_status ?? 'pending';
        $status = $status instanceof BackedEnum ? (string) $status->value : (string) $status;

        return $this->subject('Order '.Str::title(str_replace('_', ' ', $status)).' - #'.($this->data['order']?->order_number ?? 'N/A'))
            ->view('emails.order-approval')
            ->text('emails.text.order-approval')
            ->with($this->data + $this->organizationBranding() + ['approvalStatusValue' => $status]);
    }
}
