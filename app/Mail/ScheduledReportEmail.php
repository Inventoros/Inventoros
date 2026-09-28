<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Mail\Concerns\UsesOrganizationBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled saved-report delivery with the rendered report attached.
 *
 * The report is rendered in DeliverScheduledReportJob (as the report owner,
 * with their permissions re-checked) and this mailable is SENT from there,
 * never queued: queuing would serialize the report into the jobs and
 * failed_jobs tables, so queue() refuses. The org's mailer is selected in
 * build().
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
        public string $content,
    ) {
        $this->data = ['organization_id' => $organizationId];
    }

    /** The attachment bytes. */
    public function attachmentContent(): string
    {
        return $this->content;
    }

    /**
     * Never queue this mailable: the payload would carry the report itself.
     */
    public function queue(Queue $queue): mixed
    {
        throw new \LogicException('Scheduled report emails carry report data and must be sent, not queued.');
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
