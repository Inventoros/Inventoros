<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a write sent from a browser tab that still shows another
 * organization.
 *
 * The organization switcher changes the active organization for the whole
 * session, so a second tab opened before the switch still holds the previous
 * organization's forms. The app sends the organization each page was rendered
 * for in the X-Inventoros-Organization header; when it no longer matches the
 * active organization, the write is not applied (it would land in the wrong
 * organization) and the user is sent back to the dashboard of the active one.
 * Reads and requests without the header are unaffected.
 */
class EnsureActiveOrganizationMatches
{
    public const HEADER = 'X-Inventoros-Organization';

    /** Routes that must work from any tab: changing organization and signing out. */
    private const ALWAYS_ALLOWED = ['organizations.switch', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $sent = $request->headers->get(self::HEADER);

        if ($sent === null || $sent === '' || $request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || (string) (int) $user->organization_id === $sent) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        $message = 'You switched organization in another tab, so this change was not saved. Check the organization and try again.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message, 'error' => 'organization_changed'], 409);
        }

        return redirect()->route('dashboard', status: 303)->with('warning', $message);
    }
}
