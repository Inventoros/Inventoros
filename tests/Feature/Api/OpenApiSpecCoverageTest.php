<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * docs/api/openapi.yaml is maintained by hand, so it silently falls behind
 * the routes. Every /api/v1 route must have a matching path and verb in it.
 */
class OpenApiSpecCoverageTest extends TestCase
{
    public function test_every_api_route_is_documented(): void
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
        $normalize = fn (string $path): string => preg_replace('/\{[^}]+\}/', '{}', $path);

        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $verb) {
                $documented[strtoupper($verb).' '.$normalize($path)] = true;
            }
        }

        $missing = [];
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = $normalize(substr($route->uri(), strlen('api/v1')));

            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['HEAD', 'PATCH', 'OPTIONS'], true)) {
                    continue;
                }

                $checked++;
                if (! isset($documented["{$verb} {$path}"])) {
                    $missing[] = "{$verb} /api/v1".substr($route->uri(), strlen('api/v1'));
                }
            }
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertSame([], $missing, "Routes missing from docs/api/openapi.yaml:\n".implode("\n", $missing));
    }
}
