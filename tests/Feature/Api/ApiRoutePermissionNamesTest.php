<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
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
    use BuildsApiFixtures, RefreshDatabase;

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
                    $this->assertContains($name, $valid, "Route {$route->uri()} is gated on unknown permission '{$name}'.");
                }
            }
        }

        $this->assertGreaterThan(50, $checked);
    }

    /**
     * A role carrying a string that is not a real permission (for example
     * one written by hand or left over from an old seeder) must not open a
     * route. The categories, locations and stock-adjustment reads used to
     * accept `view_categories`, `view_locations` and `view_stock_adjustments`.
     */
    public function test_phantom_permission_strings_grant_nothing(): void
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Acme');
        $user = $this->makeMember($org, ['view_categories', 'view_locations', 'view_stock_adjustments']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/categories')->assertForbidden();
        $this->getJson('/api/v1/locations')->assertForbidden();
        $this->getJson('/api/v1/stock-adjustments')->assertForbidden();
    }

    /**
     * The REST reads use the same permissions as the web pages.
     */
    public function test_reads_use_the_web_permissions(): void
    {
        $this->markInstalled();
        $org = $this->makeOrganization('Acme');

        Sanctum::actingAs($this->makeMember($org, ['manage_categories']));
        $this->getJson('/api/v1/categories')->assertOk();

        Sanctum::actingAs($this->makeMember($org, ['manage_locations']));
        $this->getJson('/api/v1/locations')->assertOk();

        Sanctum::actingAs($this->makeMember($org, ['manage_stock']));
        $this->getJson('/api/v1/stock-adjustments')->assertOk();
    }
}
