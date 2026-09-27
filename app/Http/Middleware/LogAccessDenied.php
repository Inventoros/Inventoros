<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SecurityEventLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records an authenticated request that ends in 403 to the security log.
 *
 * Sits on the web and api groups so it sees the final response whichever
 * layer refused the request: the permission middleware (Inertia 403 page or
 * JSON), a policy (AuthorizationException), or an abort(403) in a controller.
 * The logger writes at most once per request and throttles repeats.
 */
final class LogAccessDenied
{
    public function __construct(private readonly SecurityEventLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_FORBIDDEN) {
            $user = $request->user();

            if ($user instanceof User) {
                $this->logger->recordPermissionDenied($request, $user);
            }
        }

        return $response;
    }
}
