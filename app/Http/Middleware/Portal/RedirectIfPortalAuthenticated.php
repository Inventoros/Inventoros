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
 * Sends a contact who is already signed in to this portal to its dashboard
 * instead of the sign-in and password pages.
 */
final class RedirectIfPortalAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = PortalContext::organization($request);
        $contact = Auth::guard('customer')->user();

        if ($contact instanceof CustomerContact
            && (int) $contact->organization_id === (int) $organization->id
            && $contact->canSignIn()) {
            return redirect()->route('portal.dashboard', ['organization' => $organization->slug]);
        }

        return $next($request);
    }
}
