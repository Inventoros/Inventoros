<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Services\UserActivityAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Alerts an organization admin about account activity: a new user, a user
 * promoted to admin, or repeated failed sign-ins against one account.
 */
class UserActivityAlertEmail extends Mailable
{
    use AppliesOrganizationMailConfig, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
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

        return $this->subject($this->subjectLine())
            ->view('emails.user-activity-alert')
            ->with($this->data);
    }

    public function subjectLine(): string
    {
        $name = $this->data['subject_name'] ?? 'a user';

        return match ($this->data['type'] ?? null) {
            UserActivityAlertService::TYPE_USER_CREATED => "New user added: {$name}",
            UserActivityAlertService::TYPE_PROMOTED_TO_ADMIN => "User promoted to admin: {$name}",
            UserActivityAlertService::TYPE_REPEATED_FAILED_LOGINS => "Repeated failed sign-ins for {$name}",
            default => 'Account activity alert',
        };
    }
}
