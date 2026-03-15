<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that redirects users with 2FA enabled to the challenge page
 * if they haven't verified their TOTP code for the current session.
 */
class EnsureTwoFactorVerified
{
    /**
     * Routes that should be excluded from the 2FA check.
     *
     * @var array<string>
     */
    protected array $except = [
        'two-factor.challenge',
        'two-factor.challenge.verify',
        'two-factor.setup',
        'two-factor.enable',
        'two-factor.disable',
        'logout',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip if not authenticated
        if (!$user) {
            return $next($request);
        }

        // Skip if 2FA is not enabled for this user
        if (!$user->two_factor_enabled) {
            return $next($request);
        }

        // Skip if already verified this session
        if ($request->session()->get('two_factor_verified', false)) {
            return $next($request);
        }

        // Skip excluded routes
        $currentRoute = $request->route()?->getName();
        if ($currentRoute && in_array($currentRoute, $this->except)) {
            return $next($request);
        }

        return redirect()->route('two-factor.challenge');
    }
}
