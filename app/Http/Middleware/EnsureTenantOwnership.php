<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer 404 for any route-bound model owned by another organization.
 *
 * Several tenant models (returns, transfers, audits, webhooks, users) carry an
 * organization_id but not the global OrganizationScope, so route-model
 * binding resolves a foreign row. Controllers check ownership too, but that
 * runs after FormRequest validation, which would otherwise answer a foreign
 * id with a 422 and confirm it exists. This runs before the controller, so a
 * foreign id is always an indistinguishable 404. Rows with a null
 * organization_id are global (system roles, permission sets) and pass.
 */
final class EnsureTenantOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if ($user !== null && $route !== null) {
            foreach ($route->parameters() as $parameter) {
                if (! $parameter instanceof Model) {
                    continue;
                }

                $owner = $parameter->getAttribute('organization_id');

                if ($owner !== null && (int) $owner !== (int) $user->organization_id) {
                    return response()->json([
                        'message' => 'Resource not found',
                        'error' => 'not_found',
                    ], 404);
                }
            }
        }

        return $next($request);
    }
}
