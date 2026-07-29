<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for checking user permissions on API routes.
 *
 * Returns JSON error responses for unauthenticated and unauthorized access.
 * Supports checking for any or all of specified permissions.
 */
final class CheckApiPermission
{
    /**
     * Handle an incoming API request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  $permission  The required permission
     * @param  string  $guard  Check mode: 'any' or 'all' for multiple permissions
     */
    public function handle(Request $request, Closure $next, string $permission, string $guard = 'any'): Response
    {
        if (! $request->user()) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'unauthenticated',
            ], 401);
        }

        // Split permission string if multiple permissions are provided
        $permissions = explode('|', $permission);

        $user = $request->user();

        // Enforce BOTH the user's role permissions AND the acting token's
        // declared abilities. Authz was previously role-only, so a scoped
        // ("read-only") personal access token — minted with specific abilities
        // via /api/v1/tokens — still carried the user's full role privileges.
        // A token that declares a specific ability set must now also be granted
        // the ability. Session auth (no PersonalAccessToken), a token with no
        // declared scope, and a `*` token are all unrestricted.
        $tokenAllows = self::tokenAllows($user->currentAccessToken());

        if ($guard === 'all') {
            // The user must hold ALL permissions and the token must allow ALL.
            $hasPermission = $user->hasAllPermissions($permissions)
                && collect($permissions)->every($tokenAllows);
        } else {
            // There must exist a permission the user holds AND the token allows.
            $hasPermission = collect($permissions)->contains(
                fn (string $p): bool => $user->hasPermission($p) && $tokenAllows($p)
            );
        }

        if (! $hasPermission) {
            return response()->json([
                'message' => 'You do not have the required permissions to perform this action.',
                'error' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Build a predicate for whether the acting token grants a given ability.
     *
     * A token is unrestricted (the predicate always returns true) when it is
     * not a personal access token (session/TransientToken), declares no
     * abilities, or declares the `*` wildcard. Only a token that declares a
     * specific ability set restricts to it.
     *
     * @return callable(string): bool
     */
    public static function tokenAllows(mixed $token): callable
    {
        $abilities = $token instanceof PersonalAccessToken ? $token->abilities : null;

        if (! is_array($abilities) || $abilities === [] || in_array('*', $abilities, true)) {
            return fn (string $p): bool => true;
        }

        return fn (string $p): bool => in_array($p, $abilities, true);
    }
}
