<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * resources/js/lib/routePermissions.js tells the pages which permissions
 * each page needs, so buttons and links that would only lead to a 403 are
 * hidden. It must match the routes' `permission:` middleware exactly.
 */
class RoutePermissionsMapTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    private function jsMap(): array
    {
        $source = (string) file_get_contents(resource_path('js/lib/routePermissions.js'));
        $this->assertSame(1, preg_match('/export const ROUTE_PERMISSIONS = (\{.*?\n\});/s', $source, $match));

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, list<string>>
     */
    private function routeMap(): array
    {
        $map = [];

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (! str_starts_with(ltrim($route->getActionName(), '\\'), 'App\\Http\\Controllers')) {
                continue;
            }

            $specs = [];
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $specs[] = substr($middleware, strlen('permission:'));
                }
            }

            if ($specs !== []) {
                $map[$name] = $specs;
            }
        }

        ksort($map);

        return $map;
    }

    public function test_the_page_permission_map_matches_the_routes(): void
    {
        $js = $this->jsMap();
        ksort($js);

        $this->assertGreaterThan(80, count($js));
        $this->assertSame($this->routeMap(), $js, 'resources/js/lib/routePermissions.js is out of date.');
    }
}
