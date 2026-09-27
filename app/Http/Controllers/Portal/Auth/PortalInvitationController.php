<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Auth;

use App\Http\Controllers\Controller;
use App\Models\CustomerContact;
use App\Services\Portal\PortalInvitationService;
use App\Services\SecurityEventLogger;
use App\Support\Portal\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting a portal invitation: the contact sets a password through the
 * signed, expiring link they were emailed, and is signed in.
 *
 * Any link that is unsigned, tampered with, expired, superseded by a resend,
 * already used, or for a revoked contact is refused with a 403.
 */
class PortalInvitationController extends Controller
{
    public function __construct(
        private readonly PortalInvitationService $invitations,
        private readonly SecurityEventLogger $security,
    ) {}

    public function show(Request $request, string $contact): Response
    {
        $record = $this->resolve($request, $contact);

        return Inertia::render('Portal/Auth/AcceptInvitation', [
            'name' => $record->name,
            'email' => $record->email,
            'acceptUrl' => $request->fullUrl(),
        ]);
    }

    public function accept(Request $request, string $contact): RedirectResponse
    {
        $record = $this->resolve($request, $contact);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $record->forceFill([
            'password' => Hash::make($validated['password']),
            'activated_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        if (! $record->canSignIn()) {
            // The customer was deactivated after the invite went out: the
            // password is set, but there is nothing to sign in to yet.
            abort(403, 'This portal account is not active.');
        }

        Auth::guard('customer')->login($record);
        $request->session()->regenerate();
        PortalLoginController::recordLogin($this->security, $record, 'invitation');

        return redirect()
            ->route('portal.dashboard', ['organization' => PortalContext::organization($request)->slug])
            ->with('success', 'Welcome! Your password is set.');
    }

    private function resolve(Request $request, string $contactId): CustomerContact
    {
        $organization = PortalContext::organization($request);

        abort_unless($request->hasValidSignature(), 403, 'This invitation link is invalid or has expired.');
        abort_unless(ctype_digit($contactId), 403, 'This invitation link is invalid or has expired.');

        $contact = CustomerContact::where('organization_id', $organization->id)
            ->whereKey((int) $contactId)
            ->first();

        abort_unless(
            $contact !== null && $this->invitations->isCurrentInvitation($contact, $request->query('v')),
            403,
            'This invitation link is invalid or has expired.',
        );

        return $contact;
    }
}
