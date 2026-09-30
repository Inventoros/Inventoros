<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PluginService;
use App\Services\PluginUIService;
use App\Support\ArtisanProcess;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\Feature\Concerns\CreatesPluginUiUsers;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * `php artisan route:cache` (and `optimize`, which UPGRADE.md, the cPanel
 * install and the updater all run) boots a second application inside the
 * same PHP process and caches whatever routes it registers. Plugins used to be
 * loaded with require_once, which is a no-op the second time, so that
 * application never ran register_page() and every plugin page 404ed once
 * routes were cached.
 *
 * The second application needs a database it can see, so these tests use a
 * SQLite file instead of the suite's in-memory one.
 */
final class PluginRoutesSurviveRouteCacheTest extends TestCase
{
    use CreatesPluginUiUsers;
    use InteractsWithFixturePlugins;

    private string $database;

    private string $routeCache;

    private bool $helloAssetsExisted;

    protected function setUp(): void
    {
        $this->database = str_replace('\\', '/', sys_get_temp_dir()).'/inventoros-route-cache-'.uniqid().'.sqlite';
        touch($this->database);
        $this->setEnv('DB_DATABASE', $this->database);

        // Relative to the base path: Laravel only treats paths starting with
        // a slash as absolute, which a Windows drive path does not.
        $relative = 'storage/app/testing/routes-'.uniqid().'.php';
        $this->setEnv('APP_ROUTES_CACHE', $relative);

        parent::setUp();

        $this->routeCache = base_path($relative);
        File::ensureDirectoryExists(dirname($this->routeCache));
        $this->helloAssetsExisted = File::isDirectory(public_path('plugin-assets/hello-world'));

        Artisan::call('migrate', ['--force' => true]);
        $this->createPluginUiUsers();
    }

    protected function tearDown(): void
    {
        $this->restoreApplication();
        $this->tearDownFixturePlugins();
        File::deleteDirectory(storage_path('app/testing'));
        if (! $this->helloAssetsExisted) {
            File::deleteDirectory(public_path('plugin-assets/hello-world'));
        }
        Mockery::close();
        $this->closeConnections($this->app->make('db'));

        parent::tearDown();

        $this->setEnv('APP_ROUTES_CACHE', null);
        $this->setEnv('DB_DATABASE', ':memory:');
        // An application booted during the test can keep the file open, and
        // Windows will not delete an open file; retry at shutdown.
        $database = $this->database;
        $delete = static function () use ($database): bool {
            set_error_handler(static fn (): bool => true);
            try {
                return ! is_file($database) || unlink($database);
            } finally {
                restore_error_handler();
            }
        };
        if (! $delete()) {
            register_shutdown_function($delete);
        }
    }

    private function closeConnections(DatabaseManager $db): void
    {
        foreach (array_keys($db->getConnections()) as $name) {
            $db->purge($name);
        }
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    /**
     * route:cache leaves the container and the facades pointing at the
     * application it booted; point them back at the test's.
     */
    private function restoreApplication(): void
    {
        $booted = Container::getInstance();
        if ($booted !== $this->app && $booted->bound('db')) {
            // Let go of the SQLite file (Windows will not delete an open file).
            $this->closeConnections($booted->make('db'));
        }

        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    private function cacheRoutesInProcess(): string
    {
        $this->assertSame(0, Artisan::call('route:cache'));
        $this->restoreApplication();

        $this->assertFileExists($this->routeCache);

        return (string) File::get($this->routeCache);
    }

    /**
     * Serve the next requests from the cached route table, as a fresh
     * request would after `route:cache`.
     */
    private function useCachedRoutes(): void
    {
        require $this->routeCache;
    }

    public function test_an_active_plugins_pages_are_in_the_route_cache_and_answer(): void
    {
        app(PluginService::class)->activatePlugin('hello-world');

        $cached = $this->cacheRoutesInProcess();

        $this->assertStringContainsString('hello-world.index', $cached, 'The plugin page route must be in the cached route table.');

        $this->useCachedRoutes();

        $this->actingAs($this->staff)->get('/hello-world')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Plugin::hello-world/Hello'));
    }

    public function test_caching_routes_twice_in_one_process_keeps_the_plugin_pages(): void
    {
        app(PluginService::class)->activatePlugin('hello-world');

        $this->cacheRoutesInProcess();
        $cached = $this->cacheRoutesInProcess();

        $this->assertStringContainsString('hello-world.index', $cached);
    }

    public function test_the_rebuild_after_activation_caches_the_new_plugins_pages(): void
    {
        // A cached table from before the plugin was active.
        $this->assertStringNotContainsString('hello-world.index', $this->cacheRoutesInProcess());
        $this->app->forgetInstance('routes.cached');
        $this->assertTrue($this->app->routesAreCached());

        // Run the rebuild in this process: the real subprocess would boot
        // from .env rather than this test's database.
        $processes = Mockery::mock(ArtisanProcess::class);
        $processes->shouldReceive('available')->andReturn(true);
        $processes->shouldReceive('run')->once()->with('route:cache')->andReturnUsing(function () {
            Artisan::call('route:cache');
            $this->restoreApplication();

            return '';
        });
        $this->app->instance(ArtisanProcess::class, $processes);

        app(PluginService::class)->activatePlugin('hello-world');

        $this->assertStringContainsString('hello-world.index', (string) File::get($this->routeCache));

        $this->useCachedRoutes();
        $this->actingAs($this->staff)->get('/hello-world')->assertOk();
    }

    public function test_a_plugin_that_returns_a_registration_closure_registers_in_every_application(): void
    {
        $slug = $this->makeFixturePlugin(files: [
            'Plugin.php' => "<?php\nreturn function (): void {\n    register_page('fixture-closure.page', 'Plugin::fixture/Page', ['uri' => '/fixture-closure']);\n};\n",
        ]);

        app(PluginService::class)->activatePlugin($slug);
        $this->assertNotNull(app(PluginUIService::class)->getCustomPage('fixture-closure.page'));

        $this->assertStringContainsString('fixture-closure.page', $this->cacheRoutesInProcess());
        $this->assertStringContainsString('fixture-closure.page', $this->cacheRoutesInProcess());
    }

    public function test_a_plugin_declaring_a_named_function_is_not_loaded_twice_and_does_not_crash(): void
    {
        $function = 'fixture_route_cache_fn_'.uniqid();
        $slug = $this->makeFixturePlugin(files: [
            'Plugin.php' => "<?php\nfunction {$function}(): string { return 'x'; }\nregister_page('fixture-named.page', 'Plugin::fixture/Page', ['uri' => '/fixture-named']);\n",
        ]);

        app(PluginService::class)->activatePlugin($slug);

        // The second application cannot re-run a file that declares a
        // function; it skips it (with a warning) instead of dying.
        $this->assertSame(0, Artisan::call('route:cache'));
        $this->restoreApplication();
        $this->assertTrue(function_exists($function));
    }

    public function test_a_plugin_is_loaded_once_per_application(): void
    {
        $slug = $this->makeFixturePlugin(files: [
            'Plugin.php' => "<?php\n\$GLOBALS['fixture_plugin_calls'][] = 'loaded';\n",
        ]);

        $service = app(PluginService::class);
        $service->activatePlugin($slug);
        $service->loadActivePlugins();
        app(PluginService::class)->loadActivePlugins();

        $this->assertSame(['loaded'], $this->fixtureCalls());
    }
}
