<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portal;

use App\Models\Auth\Organization;
use App\Support\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves /portal/{organization} to an active organization with the portal
 * switched on, or 404s. A disabled portal is indistinguishable from one that
 * does not exist.
 */
final class ResolvePortalOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('organization');

        $organization = is_string($slug) && $slug !== ''
            ? Organization::query()
                ->where('slug', $slug)
                ->where('is_active', true)
                ->where('portal_enabled', true)
                ->first()
            : null;

        abort_if($organization === null, 404);

        $request->attributes->set(PortalContext::ORGANIZATION, $organization);

        // Portal controllers generate portal URLs without passing the slug
        // each time, and the slug is not handed to controller actions as an
        // argument.
        URL::defaults(['organization' => $organization->slug]);
        $request->route()->forgetParameter('organization');

        return $next($request);
    }
}
