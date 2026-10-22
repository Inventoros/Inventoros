<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Revoke staff browser sessions when their password changes. */
final class AuthenticateStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->user();
        if (! $request->hasSession() || $user === null) {
            return $next($request);
        }

        $expected = $guard->hashPasswordForCookie($user->getAuthPassword());
        $stored = $request->session()->get('password_hash_web');

        // Legacy sessions without a binding must sign in again: they cannot
        // prove which password authenticated them before this protection existed.
        if (! is_string($stored) || ! hash_equals($expected, $stored)) {
            $guard->logoutCurrentDevice();
            $request->session()->flush();
            throw new AuthenticationException('Unauthenticated.', ['web']);
        }

        $response = $next($request);

        // A password change in this browser preserves this session. Other
        // browsers retain the previous binding and fail their next request.
        if ($guard->check()) {
            $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($guard->user()->getAuthPassword()));
        }

        return $response;
    }
}
