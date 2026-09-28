<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\UsesOrganizationBranding;
use App\Models\CustomerContact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a customer contact to the customer portal. Carries the signed,
 * expiring set-password link, built at send time by PortalInvitationService.
 */
class PortalInvitationEmail extends Mailable
{
    use Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Carries the organization id for the branding concern.
     *
     * @var array{organization_id: int}
     */
    public array $data;

    public function __construct(
        public CustomerContact $contact,
        public string $acceptUrl,
        public int $expiresInDays,
    ) {
        $this->data = ['organization_id' => (int) $contact->organization_id];
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

        if ($branding['brandEmail']) {
            $this->replyTo($branding['brandEmail'], $branding['brandName']);
        }

        return $this->subject("You're invited to the {$branding['brandName']} customer portal")
            ->view('emails.portal-invitation')
            ->text('emails.text.portal-invitation')
            ->with($branding + [
                'emailType' => 'portal_invitation',
                'transactional' => true,
            ]);
    }
}
