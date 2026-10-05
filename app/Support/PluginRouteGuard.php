<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\PluginUIService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Refuses, at activation, a plugin whose pages or routes would collide with
 * the application's (or another active plugin's) routes.
 *
 * Without it a colliding page was skipped at boot with only a log line, and a
 * colliding raw route could shadow a core one. A plugin page or route
 * conflicts when it:
 *
 * - reuses an existing route name, or claims another plugin's plg.{slug}.*
 *   name space;
 * - asks for a URI an existing route already answers for the same method, or
 *   a reserved prefix (pages only, see PluginPageRoutes::RESERVED_PREFIXES);
 * - lives under a first URI segment an existing route uses (for example
 *   /cycle-counts/...), unless it is inside the plugin's own namespace:
 *   /p/{slug}/..., /api/v1/plugins/{slug}/... or /webhooks/plugins/{slug}/...
 *   (core never registers routes there).
 */
final class PluginRouteGuard
{
    /**
     * URI prefixes reserved for plugins, each followed by the plugin's slug.
     *
     * @var array<int, string>
     */
    public const PLUGIN_URI_PREFIXES = ['p', 'api/v1/plugins', 'webhooks/plugins'];

    public const PLUGIN_NAME_PREFIX = 'plg.';

    /**
     * The routes registered right now, to tell a plugin's new routes apart.
     * The route objects are kept: a plugin route on exactly the same method
     * and URI replaces the earlier one in the collection, so it could not be
     * found there afterwards.
     *
     * @return array<int, RoutingRoute> keyed by spl_object_id
     */
    public static function snapshot(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routes[spl_object_id($route)] = $route;
        }

        return $routes;
    }

    /**
     * Throw when a page $slug registered, or a route it added since
     * $before was taken, collides with an existing route.
     *
     * @param  array<int, RoutingRoute>  $before  snapshot() taken before the plugin loaded
     *
     * @throws RuntimeException naming every conflict and the namespace to use
     */
    public static function assertNoConflicts(string $slug, array $before): void
    {
        $pages = array_filter(
            app(PluginUIService::class)->getCustomPages(),
            fn (array $page) => ($page['owner'] ?? null) === $slug,
        );

        // A cached route for one of this plugin's own pages is not a conflict.
        $existing = array_values(array_filter(
            $before,
            fn (RoutingRoute $route) => ! isset($pages[(string) ($route->defaults[PluginPageRoutes::PAGE_PARAMETER] ?? '')]),
        ));

        $added = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => ! isset($before[spl_object_id($route)]),
        ));

        $problems = [];

        foreach ($pages as $name => $page) {
            $uri = PluginPageRoutes::uriFor($name, $page);
            $problems[] = self::nameProblem($slug, "page \"{$name}\"", $name, $existing);
            $problems[] = PluginPageRoutes::isReserved($uri)
                ? "The page \"{$name}\" uses /{$uri}, under a reserved prefix."
                : self::uriProblem($slug, "page \"{$name}\"", $uri, ['GET'], $existing);
        }

        foreach ($added as $route) {
            /** @var RoutingRoute $route */
            $label = 'route '.implode('|', array_diff($route->methods(), ['HEAD'])).' /'.trim($route->uri(), '/');
            $problems[] = self::nameProblem($slug, $label, $route->getName(), $existing);
            $problems[] = self::uriProblem($slug, $label, trim($route->uri(), '/'), $route->methods(), $existing);
        }

        $problems = array_values(array_filter($problems));

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems)
                ." Plugin routes must be namespaced: names \"plg.{$slug}.*\", URIs under \"/p/{$slug}/\""
                ." (API routes under \"/api/v1/plugins/{$slug}/\", inbound webhooks under \"/webhooks/plugins/{$slug}/\").");
        }
    }

    /**
     * @param  array<int, RoutingRoute>  $existing
     */
    private static function nameProblem(string $slug, string $label, ?string $name, array $existing): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (str_starts_with($name, self::PLUGIN_NAME_PREFIX) && ! str_starts_with($name, self::PLUGIN_NAME_PREFIX.$slug.'.')) {
            return "The {$label} is named \"{$name}\", inside another plugin's \"plg.*\" names.";
        }

        foreach ($existing as $route) {
            if ($route->getName() === $name) {
                return "The {$label} is named \"{$name}\", which the application route for /".trim($route->uri(), '/').' already uses.';
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $methods
     * @param  array<int, RoutingRoute>  $existing
     */
    private static function uriProblem(string $slug, string $label, string $uri, array $methods, array $existing): ?string
    {
        foreach (array_diff($methods, ['HEAD']) as $method) {
            $request = Request::create('/'.$uri, $method);

            foreach ($existing as $route) {
                if ($route->matches($request)) {
                    return "The {$label} answers {$method} /{$uri}, which the application route \"".($route->getName() ?? $route->uri()).'" already answers.';
                }
            }
        }

        $namespace = self::pluginNamespace($uri);
        if ($namespace !== null) {
            return $namespace === $slug ? null : "The {$label} uses /{$uri}, inside the plugin \"{$namespace}\"'s namespace.";
        }

        $first = strtolower(explode('/', $uri)[0]);
        if ($first === '') {
            return null;
        }

        foreach ($existing as $route) {
            if (strtolower(explode('/', trim($route->uri(), '/'))[0]) === $first) {
                return "The {$label} uses /{$uri}, inside /{$first}/, which the application route \"".($route->getName() ?? $route->uri()).'" uses.';
            }
        }

        return null;
    }

    /**
     * The plugin slug a URI's reserved plugin namespace names, if it is in one.
     */
    private static function pluginNamespace(string $uri): ?string
    {
        foreach (self::PLUGIN_URI_PREFIXES as $prefix) {
            if (preg_match('#^'.preg_quote($prefix, '#').'/([^/]+)#', $uri, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
