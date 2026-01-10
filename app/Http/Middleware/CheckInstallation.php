<?php

namespace App\Http\Middleware;

use App\Models\System\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckInstallation
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

        // Check if application is installed
        if (!$this->isInstalled()) {
            return redirect()->route('install.index');
        }

        return $next($request);
    }

    /**
     * Check if the application is installed.
     */
    protected function isInstalled(): bool
    {
        try {
            return SystemSetting::get('installed', false) === true;
        } catch (\Exception $e) {
            Log::warning('Installation check failed - assuming not installed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
