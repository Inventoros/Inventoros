<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portal;

use App\Models\CustomerContact;
use App\Support\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits only a contact of THIS portal's organization who may still sign in
 * (not revoked, password set, customer active). A contact whose access has
 * been withdrawn is signed out on the spot. A contact of another
 * organization is treated as a guest here; their own session is left alone.
 */
final class AuthenticatePortalContact
{
    /** Session key for the page to return to after signing in. Kept apart from the staff `url.intended`. */
    public const INTENDED_KEY = 'portal.intended';

    /** Session key for a fingerprint of the contact's password at sign-in. */
    public const PASSWORD_KEY = 'portal.password_hash';

    /**
     * Remember which password this session signed in with, so a later
     * password change (a reset from another device) ends this session.
     */
    public static function rememberPassword(Request $request, CustomerContact $contact): void
    {
        $request->session()->put(self::PASSWORD_KEY, self::fingerprint($contact));
    }

    /**
     * Like Laravel's AuthenticateSession, but for the customer guard only:
     * that middleware flushes the whole session, which would also sign out a
     * staff user in the same browser. Sessions from before this check get
     * the fingerprint stored on their next request.
     */
    private static function passwordUnchanged(Request $request, CustomerContact $contact): bool
    {
        $stored = $request->session()->get(self::PASSWORD_KEY);

        if (! is_string($stored)) {
            self::rememberPassword($request, $contact);

            return true;
        }

        return hash_equals($stored, self::fingerprint($contact));
    }

    private static function fingerprint(CustomerContact $contact): string
    {
        return hash_hmac('sha256', (string) $contact->getAuthPassword(), (string) config('app.key'));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $organization = PortalContext::organization($request);
        $guard = Auth::guard('customer');
        $contact = $guard->user();

        if ($contact instanceof CustomerContact && (int) $contact->organization_id === (int) $organization->id) {
            if ($contact->canSignIn() && self::passwordUnchanged($request, $contact)) {
                $request->attributes->set(PortalContext::CONTACT, $contact);

                return $next($request);
            }

            // Only the portal guard is signed out: a staff session sharing
            // this browser session is left alone.
            $guard->logout();
            $request->session()->forget(self::PASSWORD_KEY);
        }

        if ($request->expectsJson()) {
            abort(401);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put(self::INTENDED_KEY, $request->fullUrl());
        }

        return redirect()->route('portal.login', ['organization' => $organization->slug]);
    }
}
