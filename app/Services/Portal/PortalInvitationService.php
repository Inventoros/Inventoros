<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Enums\SecurityEvent;
use App\Mail\PortalInvitationEmail;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\SecurityEventLogger;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Invites customer contacts to the portal, and revokes them.
 *
 * The invitation link is a temporary signed URL that also carries the
 * contact's invited_at timestamp (`v`). Resending moves invited_at, so every
 * earlier link stops matching; accepting sets a password, after which the
 * link is refused; revoking sets revoked_at, which is refused too. The
 * signature covers the contact id, so a link cannot be pointed at another
 * contact.
 */
final class PortalInvitationService
{
    public const EXPIRES_IN_DAYS = 7;

    public function __construct(private readonly SecurityEventLogger $security) {}

    /**
     * Create (or re-invite) a contact for $customer and email them a link.
     */
    public function invite(Customer $customer, string $name, string $email, User $inviter): CustomerContact
    {
        $contact = CustomerContact::create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'name' => $name,
            'email' => $email,
        ]);

        return $this->send($contact, $inviter);
    }

    /**
     * Issue a fresh invitation to an existing contact. Any earlier link stops
     * working. A revoked contact is reinstated as invited (no password) and
     * must set a new password through the link.
     */
    public function send(CustomerContact $contact, User $inviter): CustomerContact
    {
        $invitedAt = now()->startOfSecond();
        // A resend inside the same second as the previous invite would keep
        // the old link valid; step past it.
        if ($contact->invited_at !== null && $invitedAt->lessThanOrEqualTo($contact->invited_at)) {
            $invitedAt = $contact->invited_at->copy()->addSecond();
        }

        $wasRevoked = $contact->revoked_at !== null;

        $contact->forceFill([
            'invited_at' => $invitedAt,
            'invited_by' => $inviter->id,
            'revoked_at' => null,
            'password' => $wasRevoked ? null : $contact->password,
            'activated_at' => $wasRevoked ? null : $contact->activated_at,
            'remember_token' => $wasRevoked ? Str::random(60) : $contact->remember_token,
        ])->save();

        Mail::to($contact->email)->queue(new PortalInvitationEmail(
            $contact,
            $this->acceptUrl($contact),
            self::EXPIRES_IN_DAYS,
        ));

        $this->security->record(
            SecurityEvent::PORTAL_INVITE_SENT,
            $contact,
            $inviter,
            ['customer_id' => $contact->customer_id, 'email' => $contact->email],
        );

        return $contact;
    }

    /**
     * Revoke portal access: the contact can no longer sign in, any session
     * they hold ends on their next request, and outstanding invitation or
     * reset links stop working. The row is kept for the audit trail.
     */
    public function revoke(CustomerContact $contact, User $actor): void
    {
        $contact->forceFill([
            'revoked_at' => now(),
            'password' => null,
            'activated_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $this->security->record(
            SecurityEvent::PORTAL_ACCESS_REVOKED,
            $contact,
            $actor,
            ['customer_id' => $contact->customer_id, 'email' => $contact->email],
        );
    }

    /**
     * The signed, expiring set-password link for the contact's current
     * invitation.
     */
    public function acceptUrl(CustomerContact $contact): string
    {
        return URL::temporarySignedRoute(
            'portal.invitation.show',
            ($contact->invited_at ?? now())->copy()->addDays(self::EXPIRES_IN_DAYS),
            [
                'organization' => $contact->organization->slug,
                'contact' => $contact->id,
                'v' => $contact->invited_at?->getTimestamp(),
            ],
        );
    }

    /**
     * Whether $version (the `v` of a correctly signed link) is the contact's
     * current, still-acceptable invitation.
     */
    public function isCurrentInvitation(CustomerContact $contact, mixed $version): bool
    {
        return $contact->revoked_at === null
            && $contact->password === null
            && $contact->invited_at !== null
            && is_numeric($version)
            && (int) $version === $contact->invited_at->getTimestamp();
    }
}
