<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portal;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the `customer` guard the default for portal requests, so
 * $request->user(), auth()->user() and everything built on them (the staff
 * OrganizationScope, activity logging, locale) see the portal contact or
 * nobody, and never a staff user who happens to be signed in in the same
 * browser.
 */
final class UsePortalGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('customer');

        return $next($request);
    }
}
