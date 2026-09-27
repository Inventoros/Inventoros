<?php

use App\Http\Middleware\CheckApiPermission;
use App\Http\Middleware\CheckInstallation;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureTenantOwnership;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsManager;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectNonNumericModelKeys;
use App\Http\Middleware\LogAccessDenied;
use App\Http\Middleware\Portal\AuthenticatePortalContact;
use App\Http\Middleware\Portal\HandlePortalInertiaRequests;
use App\Http\Middleware\Portal\RedirectIfPortalAuthenticated;
use App\Http\Middleware\Portal\ResolvePortalOrganization;
use App\Http\Middleware\Portal\UsePortalGuard;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The customer portal runs in its own middleware group (below), not
        // `web`: it must not pass through the staff Inertia props, two-factor
        // gate or access-denied logging, all of which read the staff user.
        then: function () {
            Route::middleware('portal')
                ->prefix('portal/{organization}')
                ->name('portal.')
                ->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 404 for non-numeric ids before route-model binding hits the
        // database (PostgreSQL errors on `id = 'abc'` against a bigint).
        $middleware->web(prepend: [RejectNonNumericModelKeys::class]);
        $middleware->api(prepend: [RejectNonNumericModelKeys::class]);
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: RejectNonNumericModelKeys::class,
        );

        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
            CheckInstallation::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            EnsureTwoFactorVerified::class,
            LogAccessDenied::class,
        ]);

        // The language switcher writes this cookie from JavaScript, so it is
        // never encrypted; left encrypted, Laravel discards it on every request.
        $middleware->encryptCookies(except: [SetLocale::COOKIE]);

        $middleware->api(append: [
            SecurityHeaders::class,
            LogAccessDenied::class,
        ]);

        // Customer portal. The session, cookies and CSRF are the same as
        // `web`; the guard is switched to `customer` before anything reads
        // the user, and the portal organization is resolved (or 404s) before
        // the portal's own Inertia props are shared.
        $middleware->group('portal', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            AddLinkHeadersForPreloadedAssets::class,
            CheckInstallation::class,
            UsePortalGuard::class,
            SetLocale::class,
            ResolvePortalOrganization::class,
            HandlePortalInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'portal.auth' => AuthenticatePortalContact::class,
            'portal.guest' => RedirectIfPortalAuthenticated::class,
            'admin' => EnsureUserIsAdmin::class,
            'manager' => EnsureUserIsManager::class,
            'permission' => CheckPermission::class,
            'api.permission' => CheckApiPermission::class,
            'api.tenant' => EnsureTenantOwnership::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry when a DSN is configured.
        // No-op when SENTRY_LARAVEL_DSN is empty (the default), so this is
        // inert until an operator opts in.
        Integration::handles($exceptions);
    })->create();
