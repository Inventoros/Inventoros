<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\System\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps sessions and the cache out of the database until Inventoros is
 * installed.
 *
 * .env.example and the cPanel package store both in the database, whose
 * tables only exist once the installer has run the migrations. Reading the
 * session from a missing table made /install (and every other page) answer
 * 500 on a fresh install, so until the `installed` setting is true the file
 * drivers are used instead. Runs before the session starts.
 *
 * The result of the check is left on the request for CheckInstallation.
 */
final class UseFileDriversUntilInstalled
{
    public const ATTRIBUTE = 'inventoros.installed';

    public function handle(Request $request, Closure $next): Response
    {
        $installed = self::installed();
        $request->attributes->set(self::ATTRIBUTE, $installed);

        if (! $installed) {
            if (config('session.driver') === 'database') {
                config(['session.driver' => 'file']);
            }

            if (config('cache.default') === 'database') {
                config(['cache.default' => 'file']);
            }
        }

        return $next($request);
    }

    /**
     * Whether installation has completed. False while the database or its
     * tables do not exist yet.
     */
    public static function installed(): bool
    {
        try {
            return SystemSetting::get('installed', false) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
