<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portal;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the `customer` guard the default for the duration of a portal
 * request, so $request->user(), auth()->user() and everything built on them
 * (the staff OrganizationScope, activity logging, locale) see the portal
 * contact or nobody, and never a staff user who happens to be signed in in
 * the same browser.
 *
 * The previous default is restored once the response is built, so in a
 * long-lived process (Octane, the test runner) the next request does not
 * inherit the customer guard: staff routes use the default `auth` guard.
 */
final class UsePortalGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = Auth::getDefaultDriver();

        Auth::shouldUse('customer');

        try {
            return $next($request);
        } finally {
            Auth::shouldUse($previous);
        }
    }
}
