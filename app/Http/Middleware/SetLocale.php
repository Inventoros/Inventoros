<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the request locale.
 *
 * Order: the signed-in user's saved language preference, then the `locale`
 * cookie written by the language switcher (so guests on the login page keep
 * their choice), then config('app.locale'). Unsupported values are skipped.
 * HandleInertiaRequests shares the result with the frontend as `locale`.
 * Also runs on the REST API, so validation messages there are localized.
 */
class SetLocale
{
    public const SUPPORTED_LOCALES = [
        'en', 'es', 'fr', 'de', 'pt-BR', 'it',
        'ja', 'ko', 'zh-CN', 'ar', 'ru', 'nl', 'tr', 'pl',
    ];

    /**
     * The cookie the frontend language switcher writes. It is set from
     * JavaScript, so it is excluded from cookie encryption in bootstrap/app.php.
     */
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        // REST requests authenticate with a bearer token in route
        // middleware, after this runs, so resolve that user here too.
        $user = $request->user() ?? ($request->bearerToken() !== null ? $request->user('sanctum') : null);

        $candidates = [
            $user?->notification_preferences['preferences']['language'] ?? null,
            $request->cookie(self::COOKIE),
            config('app.locale'),
        ];

        foreach ($candidates as $locale) {
            if (is_string($locale) && in_array($locale, self::SUPPORTED_LOCALES, true)) {
                app()->setLocale($locale);
                break;
            }
        }

        return $next($request);
    }
}
