<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PluginService;
use App\Support\ArtisanProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * With `php artisan route:cache` (part of `optimize`), a plugin's routes only
 * exist once they are in the cache: activating a plugin used to leave its
 * pages 404ing, and deactivating it left them routable. Every plugin state
 * change rebuilds the route cache, or clears it when that fails.
 */
final class PluginRouteCacheTest extends TestCase
{
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    private string $routeCache;

    private Mockery\MockInterface $processes;

    protected function setUp(): void
    {
        parent::setUp();

        // Relative to the base path: Laravel only treats paths starting with
        // a slash as absolute, which a Windows drive path does not.
        $relative = 'storage/app/testing/routes-'.uniqid().'.php';
        $this->routeCache = base_path($relative);
        $this->setRouteCachePath($relative);

        $this->processes = Mockery::mock(ArtisanProcess::class);
        $this->processes->shouldReceive('available')->andReturn(true)->byDefault();
        $this->app->instance(ArtisanProcess::class, $this->processes);
    }

    protected function tearDown(): void
    {
        $this->setRouteCachePath(null);
        File::deleteDirectory(storage_path('app/testing'));
        $this->tearDownFixturePlugins();
        Mockery::close();

        parent::tearDown();
    }

    private function setRouteCachePath(?string $path): void
    {
        if ($path === null) {
            putenv('APP_ROUTES_CACHE');
            unset($_ENV['APP_ROUTES_CACHE'], $_SERVER['APP_ROUTES_CACHE']);

            return;
        }

        putenv("APP_ROUTES_CACHE={$path}");
        $_ENV['APP_ROUTES_CACHE'] = $_SERVER['APP_ROUTES_CACHE'] = $path;
    }

    private function cacheRoutes(): void
    {
        File::ensureDirectoryExists(dirname($this->routeCache));
        File::put($this->routeCache, '<?php // cached routes');
        // routesAreCached() is memoised per application.
        $this->app->forgetInstance('routes.cached');
        $this->assertTrue($this->app->routesAreCached());
    }

    public function test_activation_rebuilds_a_cached_route_table(): void
    {
        $this->cacheRoutes();
        $slug = $this->makeFixturePlugin();
        $this->processes->shouldReceive('run')->once()->with('route:cache');

        app(PluginService::class)->activatePlugin($slug);
    }

    public function test_deactivation_and_deletion_rebuild_it_too(): void
    {
        $slug = $this->makeFixturePlugin();
        app(PluginService::class)->activatePlugin($slug);

        $this->cacheRoutes();
        $this->processes->shouldReceive('run')->twice()->with('route:cache');

        app(PluginService::class)->deactivatePlugin($slug);
        app(PluginService::class)->deletePlugin($slug);
    }

    public function test_a_failed_rebuild_leaves_the_routes_uncached(): void
    {
        $this->cacheRoutes();
        $slug = $this->makeFixturePlugin();
        $this->processes->shouldReceive('run')->once()->with('route:cache')
            ->andThrow(new \RuntimeException('Unable to prepare route [x] for serialization'));

        app(PluginService::class)->activatePlugin($slug);

        $this->assertFileDoesNotExist($this->routeCache, 'A stale route cache must not survive a failed rebuild.');
    }

    public function test_without_a_php_process_the_stale_cache_is_cleared(): void
    {
        $this->cacheRoutes();
        $slug = $this->makeFixturePlugin();
        $this->processes->shouldReceive('available')->andReturn(false);
        $this->processes->shouldReceive('run')->never();

        app(PluginService::class)->activatePlugin($slug);

        $this->assertFileDoesNotExist($this->routeCache);
    }

    public function test_nothing_happens_when_routes_are_not_cached(): void
    {
        $this->assertFalse($this->app->routesAreCached());
        $slug = $this->makeFixturePlugin();
        $this->processes->shouldReceive('run')->never();
        Artisan::spy();

        app(PluginService::class)->activatePlugin($slug);

        Artisan::shouldNotHaveReceived('call');
    }
}
