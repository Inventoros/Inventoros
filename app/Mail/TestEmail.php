<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\UsesOrganizationBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable for sending test emails.
 *
 * Used to verify email configuration settings are working correctly.
 */
class TestEmail extends Mailable
{
    use Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Create a new message instance.
     *
     * @param array $data The test email data
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
        $branding = $this->organizationBranding();

        return $this->subject('Test Email - '.$branding['brandName'])
            ->view('emails.test-email')
            ->text('emails.text.test-email')
            ->with($this->data + $branding);
    }
}
