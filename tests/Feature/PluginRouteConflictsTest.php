<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\PluginService;
use App\Support\PluginPageRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\CreatesPluginUiUsers;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * A plugin whose page or route would collide with an application route (by
 * name, or by a URI the application already answers for the same method) is
 * refused at activation with a message that names the conflict, instead of
 * activating with the page silently missing. Plugins namespace their routes
 * as plg.{slug}.* names under /p/{slug}/..., which core never uses.
 */
final class PluginRouteConflictsTest extends TestCase
{
    use CreatesPluginUiUsers;
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPluginUiUsers();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        parent::tearDown();
    }

    private function pluginWith(string $registration): string
    {
        return $this->makeFixturePlugin(files: [
            'Plugin.php' => "<?php\nuse Illuminate\\Support\\Facades\\Route;\nreturn function (string \$slug) {\n{$registration}\n};\n",
        ]);
    }

    private function assertRefused(string $slug, string ...$fragments): void
    {
        try {
            app(PluginService::class)->activatePlugin($slug);
            $this->fail('Activation should have been refused.');
        } catch (\RuntimeException $e) {
            foreach ($fragments as $fragment) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
        }

        $this->assertFalse((bool) Plugin::where('slug', $slug)->value('is_active'));
    }

    public function test_a_page_inside_a_core_uri_prefix_is_refused_at_activation(): void
    {
        // Nothing answers GET /cycle-counts/sessions today, but /cycle-counts
        // is core's: the page used to vanish behind PUT /cycle-counts/{id}.
        $slug = $this->pluginWith("register_page('plg.'.\$slug.'.sessions', 'Plugin::x/Sessions', ['uri' => '/cycle-counts/sessions']);");

        $this->assertRefused($slug, '/cycle-counts/sessions', 'inside /cycle-counts/', "plg.{$slug}.*", "/p/{$slug}/");
    }

    public function test_a_page_on_a_uri_a_core_route_answers_is_refused_at_activation(): void
    {
        $slug = $this->pluginWith("register_page('plg.'.\$slug.'.create', 'Plugin::x/Create', ['uri' => '/cycle-counts/create']);");

        $this->assertRefused($slug, 'GET /cycle-counts/create', 'cycle-counts.create');
    }

    public function test_a_route_inside_another_plugins_namespace_is_refused_at_activation(): void
    {
        $slug = $this->pluginWith("Route::middleware(['web', 'auth'])->get('/p/someone-else/x', fn () => 'mine')->name('plg.someone-else.x');");

        $this->assertRefused($slug, 'plugin "someone-else"', 'another plugin');
    }

    public function test_a_page_reusing_a_core_route_name_is_refused_at_activation(): void
    {
        $slug = $this->pluginWith("register_page('cycle-counts.index', 'Plugin::x/Sessions', ['uri' => '/p/'.\$slug.'/sessions']);");

        $this->assertRefused($slug, 'cycle-counts.index');
    }

    public function test_a_page_under_a_reserved_prefix_is_refused_at_activation(): void
    {
        $slug = $this->pluginWith("register_page('plg.'.\$slug.'.hook', 'Plugin::x/Hook', ['uri' => '/webhooks/'.\$slug]);");

        $this->assertRefused($slug, 'reserved');
    }

    public function test_a_raw_route_on_a_core_uri_and_method_is_refused_at_activation(): void
    {
        $slug = $this->pluginWith("Route::middleware(['web', 'auth'])->post('/cycle-counts', fn () => 'mine')->name('plg.'.\$slug.'.store');");

        $this->assertRefused($slug, 'POST /cycle-counts', 'cycle-counts.store');
    }

    public function test_a_raw_route_reusing_a_core_route_name_is_refused_at_activation(): void
    {
        $slug = $this->pluginWith("Route::middleware(['web', 'auth'])->get('/p/'.\$slug.'/x', fn () => 'mine')->name('dashboard');");

        $this->assertRefused($slug, '"dashboard"');
    }

    public function test_namespaced_pages_and_routes_activate_and_answer(): void
    {
        $slug = $this->pluginWith(<<<'PHP'
            register_page('plg.'.$slug.'.sessions', 'Plugin::x/Sessions', ['uri' => '/p/'.$slug.'/sessions']);
            Route::middleware(['web', 'auth'])->put('/p/'.$slug.'/sessions', fn () => response()->noContent())->name('plg.'.$slug.'.sessions.update');
            PHP);

        app(PluginService::class)->activatePlugin($slug);
        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));

        // A write route on the page's own URI no longer hides the page: the
        // conflict check only counts routes that answer the same method.
        PluginPageRoutes::register();
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(Route::has("plg.{$slug}.sessions"));
        $this->actingAs($this->admin)->get("/p/{$slug}/sessions")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Plugin::x/Sessions', false));
    }

    public function test_core_never_uses_the_plugin_route_namespace(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = trim($route->uri(), '/');
            $this->assertFalse($uri === 'p' || str_starts_with($uri, 'p/'), "Core route /{$uri} is inside the plugin URI namespace /p/.");
            $this->assertFalse(str_starts_with((string) $route->getName(), 'plg.'), "Core route {$route->getName()} is inside the plugin name namespace plg.*.");
        }
    }
}
