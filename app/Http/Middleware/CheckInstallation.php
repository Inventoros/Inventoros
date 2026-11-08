<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if the application has been installed.
 *
 * Redirects uninstalled applications to the installation wizard.
 */
final class CheckInstallation
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip check for install routes
        if ($request->is('install') || $request->is('install/*')) {
            return $next($request);
        }

        // Check if application is installed (UseFileDriversUntilInstalled
        // has usually checked already, before the session started)
        $installed = $request->attributes->get(UseFileDriversUntilInstalled::ATTRIBUTE);
        if (! (is_bool($installed) ? $installed : $this->isInstalled())) {
            return redirect()->route('install.index');
        }

        return $next($request);
    }

    /**
     * Check if the application is installed.
     */
    protected function isInstalled(): bool
    {
        return InstallationState::installed();
    }
}
