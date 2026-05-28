<?php

use App\Http\Middleware\CheckApiPermission;
use App\Http\Middleware\CheckInstallation;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsManager;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
            CheckInstallation::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            EnsureTwoFactorVerified::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'manager' => EnsureUserIsManager::class,
            'permission' => CheckPermission::class,
            'api.permission' => CheckApiPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry when a DSN is configured.
        // No-op when SENTRY_LARAVEL_DSN is empty (the default), so this is
        // inert until an operator opts in.
        Integration::handles($exceptions);
    })->create();
