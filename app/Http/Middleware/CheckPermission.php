<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission  The required permission
     * @param  string  $guard  Check mode: 'any' or 'all' for multiple permissions
     */
    public function handle(Request $request, Closure $next, string $permission, string $guard = 'any'): Response
    {
        if (!$request->user()) {
            abort(401, 'Unauthenticated.');
        }

        // Split permission string if multiple permissions are provided
        $permissions = explode('|', $permission);

        if ($guard === 'all') {
            // User must have ALL specified permissions
            if (!$request->user()->hasAllPermissions($permissions)) {
                abort(403, 'You do not have the required permissions to perform this action.');
            }
        } else {
            // User must have ANY of the specified permissions (default)
            if (!$request->user()->hasAnyPermission($permissions)) {
                abort(403, 'You do not have the required permissions to perform this action.');
            }
        }

        return $next($request);
    }
}
