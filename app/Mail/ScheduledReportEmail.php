<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Mail\Concerns\UsesOrganizationBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled saved-report delivery with the rendered report attached.
 *
 * The report is rendered by the scheduler command (as the report owner, with
 * their permissions re-checked), then queued. The attachment travels base64
 * encoded because the queue payload is JSON and XLSX/PDF bytes are not valid
 * UTF-8. The org's mail configuration is applied in build(), in the worker.
 */
class ScheduledReportEmail extends Mailable
{
    use AppliesOrganizationMailConfig, Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Carries the organization id for the mail-config and branding concerns.
     *
     * @var array{organization_id: int}
     */
    public array $data;

    public function __construct(
        int $organizationId,
        public string $reportName,
        public string $frequency,
        public string $filename,
        public string $mimeType,
        public string $contentBase64,
    ) {
        $this->data = ['organization_id' => $organizationId];
    }

    /** The decoded attachment bytes. */
    public function attachmentContent(): string
    {
        return (string) base64_decode($this->contentBase64, true);
    }

    /**
     * @return $this
     */
    public function build()
    {
        $this->applyOrganizationMailConfig();

        $branding = $this->organizationBranding();

        if ($branding['brandEmail']) {
            $this->replyTo($branding['brandEmail'], $branding['brandName']);
        }

        return $this->subject("{$this->reportName}: your {$this->frequency} report from {$branding['brandName']}")
            ->view('emails.scheduled-report')
            ->text('emails.text.scheduled-report')
            ->with($branding + [
                'emailType' => 'scheduled_report',
                'transactional' => true,
                'reportName' => $this->reportName,
                'frequency' => $this->frequency,
                'filename' => $this->filename,
                'generatedAt' => now()->format('M j, Y H:i'),
            ])
            ->attachData($this->attachmentContent(), $this->filename, ['mime' => $this->mimeType]);
    }
}
