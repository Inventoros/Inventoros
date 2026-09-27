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

    public function handle(Request $request, Closure $next): Response
    {
        $organization = PortalContext::organization($request);
        $guard = Auth::guard('customer');
        $contact = $guard->user();

        if ($contact instanceof CustomerContact && (int) $contact->organization_id === (int) $organization->id) {
            if ($contact->canSignIn()) {
                $request->attributes->set(PortalContext::CONTACT, $contact);

                return $next($request);
            }

            $guard->logout();
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
