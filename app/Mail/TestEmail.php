<?php

declare(strict_types=1);

namespace App\Mail;

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
    use Queueable, SerializesModels;

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
        return $this->subject('Test Email - Inventoros')
            ->view('emails.test-email')
            ->with($this->data);
    }
}
