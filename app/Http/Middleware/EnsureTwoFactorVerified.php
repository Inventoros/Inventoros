<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that redirects users with 2FA enabled to the challenge page
 * if they haven't verified their TOTP code for the current session.
 */
class EnsureTwoFactorVerified
{
    /**
     * Session key holding the identifier of the user who passed the second
     * factor in this session. Bound to the user (not a bare flag) so a marker
     * left behind by another account, or by an earlier sign-in, never lets a
     * different user skip the challenge.
     */
    public const SESSION_KEY = 'two_factor_verified';

    /**
     * Routes that should be excluded from the 2FA check.
     *
     * Only the challenge itself (and logout) may be reached before the
     * session has passed 2FA verification. The 2FA-settings routes
     * (setup/enable/disable) are deliberately NOT exempt: once a user has
     * 2FA enabled, a session that has only completed the password step must
     * pass the challenge before it can turn the second factor off or rebind
     * it — otherwise knowing the password alone defeats 2FA. First-time
     * setup is unaffected because this middleware is a no-op while the user
     * has no 2FA enabled (see handle()).
     *
     * @var array<string>
     */
    protected array $except = [
        'two-factor.challenge',
        'two-factor.challenge.verify',
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

        // Skip if this user already passed the second factor this session
        if (self::isVerifiedFor($request, $user)) {
            return $next($request);
        }

        // Skip excluded routes
        $currentRoute = $request->route()?->getName();
        if ($currentRoute && in_array($currentRoute, $this->except)) {
            return $next($request);
        }

        return redirect()->route('two-factor.challenge');
    }

    /**
     * Whether the session passed the second factor as exactly this user.
     */
    public static function isVerifiedFor(Request $request, Authenticatable $user): bool
    {
        $verified = $request->session()->get(self::SESSION_KEY);

        // Only an identifier counts; a legacy boolean marker does not.
        if (! is_int($verified) && ! is_string($verified)) {
            return false;
        }

        return (string) $verified === (string) $user->getAuthIdentifier();
    }
}
