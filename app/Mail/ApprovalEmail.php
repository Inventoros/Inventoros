<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Mail\Concerns\UsesOrganizationBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Approval workflow email: tells an approver a request is waiting, or tells
 * the requester it was approved or rejected.
 *
 * Carries plain data (a request description from ApprovalService::describe()),
 * never models, so it serializes cleanly onto the queue.
 */
class ApprovalEmail extends Mailable
{
    use AppliesOrganizationMailConfig, Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * @param  array{kind: string, item: array<string, mixed>, actor: string, notes?: string|null, notification_url?: string, organization_id?: int}  $data
     */
    public function __construct(public array $data) {}

    public function build()
    {
        $this->applyOrganizationMailConfig();

        $reference = $this->data['item']['reference'] ?? '';

        $subject = match ($this->data['kind'] ?? 'requested') {
            'approved' => "Approved: {$reference}",
            'rejected' => "Rejected: {$reference}",
            default => "Approval needed: {$reference}",
        };

        return $this->subject($subject)
            ->view('emails.approval')
            ->text('emails.text.approval')
            ->with($this->data + $this->organizationBranding());
    }
}
