<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiPermission
{
    /**
     * Handle an incoming API request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission  The required permission
     * @param  string  $guard  Check mode: 'any' or 'all' for multiple permissions
     */
    public function handle(Request $request, Closure $next, string $permission, string $guard = 'any'): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'unauthenticated',
            ], 401);
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
            return response()->json([
                'message' => 'You do not have the required permissions to perform this action.',
                'error' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
