<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\UsesOrganizationBranding;
use App\Models\CustomerContact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Password reset link for a customer portal contact. Links to the portal's
 * own reset page for the contact's organization, never the staff one.
 */
class PortalPasswordResetEmail extends Mailable
{
    use Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Carries the organization id for the branding concern.
     *
     * @var array{organization_id: int}
     */
    public array $data;

    public string $resetUrl;

    public function __construct(public CustomerContact $contact, public string $token)
    {
        $this->data = ['organization_id' => (int) $contact->organization_id];

        $this->resetUrl = route('portal.password.reset', [
            'organization' => $contact->organization->slug,
            'token' => $token,
            'email' => $contact->email,
        ]);
    }

    /**
     * @return $this
     */
    public function build()
    {
        // Token-bearing: always the system mailer, never the organization's own
        // transport, so a set-password link is only ever handed to the
        // instance's mail provider.

        $branding = $this->organizationBranding();

        return $this->subject("Reset your {$branding['brandName']} customer portal password")
            ->view('emails.portal-password-reset')
            ->text('emails.text.portal-password-reset')
            ->with($branding + [
                'emailType' => 'portal_password_reset',
                'transactional' => true,
                'expiresInMinutes' => (int) config('auth.passwords.customer_contacts.expire', 60),
            ]);
    }
}
