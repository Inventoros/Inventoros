<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\Permission;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every `api.permission:` gate must name a real Permission enum case.
 *
 * A misspelt or invented name can never be granted (and can never appear in
 * a token's abilities, which are validated against the enum), so a gate that
 * uses one silently locks everyone out, or, when OR-ed with a real name,
 * silently collapses to the other permission.
 */
class ApiRoutePermissionNamesTest extends TestCase
{
    /**
     * Pre-existing names that are not enum cases. Each is OR-ed with a real
     * permission, so the gate still works; do not add to this list.
     */
    private const LEGACY_NON_ENUM = ['view_categories', 'view_locations', 'view_stock_adjustments'];

    public function test_every_api_permission_gate_names_a_real_permission(): void
    {
        $valid = array_column(Permission::cases(), 'value');
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'api.permission:')) {
                    continue;
                }

                $spec = explode(',', substr($middleware, strlen('api.permission:')))[0];

                foreach (explode('|', $spec) as $name) {
                    $checked++;
                    if (in_array($name, self::LEGACY_NON_ENUM, true)) {
                        continue;
                    }

                    $this->assertContains($name, $valid, "Route {$route->uri()} is gated on unknown permission '{$name}'.");
                }
            }
        }

        $this->assertGreaterThan(50, $checked);
    }
}
