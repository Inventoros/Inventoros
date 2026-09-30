<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PluginAdministration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits installation-wide plugin changes to the plugin administrator
 * organization (see PluginAdministration). Used together with
 * `permission:manage_plugins`.
 */
final class EnsurePluginAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            PluginAdministration::isAdministratorOrganization($request->user()),
            403,
            'Plugins are shared by every organization on this installation, so only the plugin administrator organization can change them.',
        );

        return $next($request);
    }
}
