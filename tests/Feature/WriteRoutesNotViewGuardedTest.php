<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A route that writes (POST, PUT, PATCH, DELETE) must never be guarded only
 * by a view_* permission: that lets a read-only user change data. The web
 * warehouse resource shipped exactly like this, with one view_warehouses
 * middleware covering store, update and destroy.
 *
 * Every permission middleware on a route must pass, so the route is safe as
 * soon as any one of them names a non-view permission.
 */
class WriteRoutesNotViewGuardedTest extends TestCase
{
    /**
     * URI prefixes whose writes are deliberately open to view_* holders.
     * Each needs a reason.
     *
     * @var array<string, string>
     */
    private const ALLOWED_URI_PREFIXES = [
        // Computes a candidate SKU or checks one for uniqueness; nothing is stored.
        'sku/' => 'read-only lookups sent as POST',
        // A user's own saved reports and their email schedules. Reading data
        // needs view_reports plus each source's view permission; the report
        // record belongs to its creator, and the controllers reject edits
        // and deletes by anyone but the owner.
        'reports/builder' => 'personal saved reports, owner-only writes',
        'api/v1/reports' => 'personal saved reports, owner-only writes',
    ];

    public function test_no_write_route_is_guarded_only_by_a_view_permission(): void
    {
        $offenders = [];
        $checked = 0;

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes() as $route) {
            $writeVerbs = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            if ($writeVerbs === []) {
                continue;
            }

            // Route::redirect() answers every verb with a redirect; it writes nothing.
            if (ltrim($route->getActionName(), '\\') === 'Illuminate\Routing\RedirectController') {
                continue;
            }

            $names = $this->permissionNames($route);
            if ($names === []) {
                continue;
            }
            $checked++;

            $onlyView = collect($names)->every(fn (string $name): bool => str_starts_with($name, 'view_'));
            if (! $onlyView) {
                continue;
            }

            if ($this->isAllowed($route)) {
                continue;
            }

            $offenders[] = implode('|', $writeVerbs).' '.$route->uri().' ('.implode(', ', $names).')';
        }

        $this->assertGreaterThan(100, $checked, 'The route scan found too few guarded write routes to be meaningful.');
        $this->assertSame([], $offenders, "Write routes guarded only by a view_* permission:\n".implode("\n", $offenders));
    }

    public function test_the_warehouse_resource_writes_need_their_own_permissions(): void
    {
        $expected = [
            'warehouses.create' => 'create_warehouses',
            'warehouses.store' => 'create_warehouses',
            'warehouses.edit' => 'edit_warehouses',
            'warehouses.update' => 'edit_warehouses',
            'warehouses.destroy' => 'delete_warehouses',
        ];

        foreach ($expected as $routeName => $permission) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} is missing.");
            $this->assertContains($permission, $this->permissionNames($route), "{$routeName} must require {$permission}.");
        }
    }

    private function isAllowed(RoutingRoute $route): bool
    {
        foreach (array_keys(self::ALLOWED_URI_PREFIXES) as $prefix) {
            if (str_starts_with($route->uri(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function permissionNames(RoutingRoute $route): array
    {
        $names = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach (['permission:', 'api.permission:'] as $prefix) {
                if (str_starts_with($middleware, $prefix)) {
                    $spec = explode(',', substr($middleware, strlen($prefix)))[0];
                    array_push($names, ...explode('|', $spec));
                }
            }
        }

        return array_values(array_unique($names));
    }
}
