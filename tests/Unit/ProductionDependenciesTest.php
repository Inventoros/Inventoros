<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Release ZIPs and the Docker image install with `composer install --no-dev`.
 * Anything the running app needs must therefore be a production dependency,
 * not something that only arrives through a dev tool.
 */
class ProductionDependenciesTest extends TestCase
{
    /**
     * Packages the app uses at runtime (routes/ai.php serves /mcp with laravel/mcp).
     */
    private const RUNTIME_PACKAGES = ['laravel/mcp'];

    private function json(string $file): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.$file), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_runtime_packages_are_required_outside_require_dev(): void
    {
        $composer = $this->json('composer.json');

        foreach (self::RUNTIME_PACKAGES as $package) {
            $this->assertArrayHasKey($package, $composer['require'], "{$package} must be in composer.json \"require\"");
            $this->assertArrayNotHasKey($package, $composer['require-dev'] ?? []);
        }
    }

    public function test_runtime_packages_are_locked_as_production_packages(): void
    {
        $lock = $this->json('composer.lock');
        $production = array_column($lock['packages'], 'name');
        $dev = array_column($lock['packages-dev'], 'name');

        foreach (self::RUNTIME_PACKAGES as $package) {
            $this->assertContains($package, $production, "{$package} is not installed by composer install --no-dev");
            $this->assertNotContains($package, $dev);
        }
    }
}
