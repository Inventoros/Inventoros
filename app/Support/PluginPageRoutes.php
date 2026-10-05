<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\PluginPageController;
use App\Services\PluginUIService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Adds a GET route for every page registered with register_page().
 *
 * Called from routes/web.php, after active plugins have loaded. Each route
 * points at PluginPageController with the page's route name as a default, so
 * routes stay cacheable; the page itself (component, permission, props) is
 * looked up at request time, and a cached route whose plugin is no longer
 * active answers 404.
 *
 * A page is always behind `auth` (a plugin's own `middleware` is added after
 * it, never instead of it; two-factor is enforced by the web group). It may
 * not reuse an existing route name or URI, and may not live under a reserved
 * prefix, so a plugin cannot take over a core route, the customer portal
 * (registered after these routes), the API, the installer or sign-in.
 *
 * Activation refuses a plugin whose pages or routes collide in the first
 * place (PluginRouteGuard), with a message naming the conflict; the checks
 * here keep an already-active plugin from taking over a route core adds
 * later.
 */
final class PluginPageRoutes
{
    public const PAGE_PARAMETER = '_plugin_page';

    /**
     * First URI segments a plugin page may never use.
     *
     * @var array<int, string>
     */
    public const RESERVED_PREFIXES = [
        'api', 'portal', 'install', 'graphql', 'graphiql', 'mcp', 'sanctum', 'webhooks', 'up',
        'storage', 'build', 'plugin-assets', 'plugins',
        'login', 'logout', 'register', 'forgot-password', 'reset-password', 'verify-email',
        'confirm-password', 'email', 'password', 'two-factor',
    ];

    public static function register(): void
    {
        foreach (app(PluginUIService::class)->getCustomPages() as $name => $page) {
            if (Route::has($name)) {
                Log::warning('Plugin page skipped: the route name is already taken', ['route' => $name]);

                continue;
            }

            $uri = self::uriFor($name, $page);

            if (self::isReserved($uri) || self::isTaken($uri)) {
                Log::warning('Plugin page skipped: the URI is reserved or already used by the application', ['route' => $name, 'uri' => $uri]);

                continue;
            }

            $middleware = array_values(array_filter(
                (array) ($page['middleware'] ?? []),
                fn ($m) => is_string($m) && $m !== 'auth',
            ));

            Route::get($uri, PluginPageController::class)
                ->middleware(['auth', ...$middleware])
                ->defaults(self::PAGE_PARAMETER, $name)
                ->name($name);
        }
    }

    /**
     * The URI a page is served at: its `uri`, or the route name with dots as
     * slashes.
     *
     * @param  array<string, mixed>  $page
     */
    public static function uriFor(string $name, array $page): string
    {
        return is_string($page['uri'] ?? null) && trim($page['uri'], '/') !== ''
            ? trim($page['uri'], '/')
            : str_replace('.', '/', $name);
    }

    public static function isReserved(string $uri): bool
    {
        $first = strtolower(explode('/', $uri)[0]);

        return in_array($first, self::RESERVED_PREFIXES, true);
    }

    /**
     * Whether an application route already answers a GET for this URI (the
     * request would never reach the plugin page, or the page would shadow a
     * later route). Routes for other methods (a plugin's own PUT on the
     * page's URI, say) do not count: Laravel matches the method first.
     */
    private static function isTaken(string $uri): bool
    {
        $request = Request::create('/'.$uri, 'GET');

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (array_key_exists(self::PAGE_PARAMETER, $route->defaults)) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (trim($route->uri(), '/') === $uri || $route->matches($request, false)) {
                return true;
            }
        }

        return false;
    }
}
