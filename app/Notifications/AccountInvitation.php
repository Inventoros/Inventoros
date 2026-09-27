<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites an imported user to choose their password.
 *
 * Imported accounts get a random password nobody knows, so the only way in is
 * this link. It is a standard password-reset token (the same broker and reset
 * page as "Forgot your password?"), so when it expires the user can simply
 * request a new one from the sign-in page.
 */
final class AccountInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $organizationName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $broker = config('auth.defaults.passwords');
        $expires = (int) config("auth.passwords.{$broker}.expire", 60);

        return (new MailMessage)
            ->subject("You have been invited to {$this->organizationName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("An account has been created for you at {$this->organizationName}. Choose a password to sign in.")
            ->action('Set your password', $url)
            ->line("This link expires in {$expires} minutes. If it has expired, use \"Forgot your password?\" on the sign-in page with this email address to get a new one.");
    }
}
