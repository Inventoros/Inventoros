<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PluginUIService;
use App\Support\PluginPageRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\CreatesPluginUiUsers;
use Tests\TestCase;

/**
 * register_page() gives a plugin a real route that renders its Plugin::
 * Inertia page, gated on the page's `permission`.
 *
 * At runtime the routes are added by routes/web.php after active plugins have
 * loaded. Here the pages are registered inside the test, so the test adds the
 * routes itself the same way.
 */
final class PluginPagesTest extends TestCase
{
    use CreatesPluginUiUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPluginUiUsers();
    }

    private function registerRoutes(): void
    {
        PluginPageRoutes::register();
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_the_web_routes_file_adds_plugin_page_routes(): void
    {
        $this->assertStringContainsString(
            'PluginPageRoutes::register()',
            (string) file_get_contents(base_path('routes/web.php'))
        );
    }

    public function test_a_registered_page_renders_its_plugin_component_with_its_props(): void
    {
        register_page('fixture.hello', 'Plugin::fixture/Hello', [
            'uri' => '/fixture/hello',
            'title' => 'Hello page',
            'props' => ['greeting' => 'Hi'],
        ]);
        $this->registerRoutes();

        $this->assertSame(url('/fixture/hello'), route('fixture.hello'));

        $this->actingAs($this->staff)->get('/fixture/hello')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Plugin::fixture/Hello')
                ->where('title', 'Hello page')
                ->where('greeting', 'Hi'));
    }

    public function test_the_uri_defaults_to_the_route_name_as_a_path(): void
    {
        register_page('fixture.reports.summary', 'Plugin::fixture/Summary');
        $this->registerRoutes();

        $this->assertSame(url('/fixture/reports/summary'), route('fixture.reports.summary'));
    }

    public function test_a_page_with_a_permission_is_refused_to_users_without_it(): void
    {
        $calls = 0;
        register_page('fixture.margins', 'Plugin::fixture/Margins', [
            'permission' => 'view_reports',
            'props' => function ($request, $user) use (&$calls) {
                $calls++;

                return ['viewer' => $user->id];
            },
        ]);
        $this->registerRoutes();

        $this->actingAs($this->staff)->get('/fixture/margins')->assertForbidden();
        $this->assertSame(0, $calls);

        $this->actingAs($this->admin)->get('/fixture/margins')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('viewer', $this->admin->id));
        $this->assertSame(1, $calls);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        register_page('fixture.hello', 'Plugin::fixture/Hello');
        $this->registerRoutes();

        $this->get('/fixture/hello')->assertRedirect(route('login'));
    }

    public function test_a_page_cannot_take_over_a_core_route_name(): void
    {
        register_page('dashboard', 'Plugin::fixture/Evil', ['uri' => '/fixture/evil']);
        $this->registerRoutes();

        $this->assertSame(url('/dashboard'), route('dashboard'));
        $this->actingAs($this->admin)->get('/fixture/evil')->assertNotFound();
    }

    public function test_a_route_whose_page_is_no_longer_registered_is_not_found(): void
    {
        // A cached route outlives the plugin that registered it.
        register_page('fixture.hello', 'Plugin::fixture/Hello');
        $this->registerRoutes();
        app(PluginUIService::class)->clear();

        $this->actingAs($this->admin)->get('/fixture/hello')->assertNotFound();
    }
}
