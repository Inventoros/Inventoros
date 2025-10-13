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

        $hasPermission = false;

        if ($guard === 'all') {
            // User must have ALL specified permissions
            $hasPermission = $request->user()->hasAllPermissions($permissions);
        } else {
            // User must have ANY of the specified permissions (default)
            $hasPermission = $request->user()->hasAnyPermission($permissions);
        }

        if (!$hasPermission) {
            // If it's an Inertia request, render the 403 page
            if ($request->header('X-Inertia')) {
                return \Inertia\Inertia::render('Errors/403', [
                    'previousUrl' => url()->previous(),
                ])->toResponse($request)->setStatusCode(403);
            }

            // Otherwise, abort with plain error
            abort(403, 'You do not have the required permissions to perform this action.');
        }

        return $next($request);
    }
}
