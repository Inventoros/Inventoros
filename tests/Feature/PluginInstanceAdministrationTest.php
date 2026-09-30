<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Plugin;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * Plugins are installed, updated, activated and removed for the whole
 * installation (files in /plugins, one `plugins` table), but manage_plugins
 * is a per-organization permission. On a multi-organization install that let
 * any organization's admin run code for every tenant. Only the plugin
 * administrator organization (INVENTOROS_PLUGIN_ADMIN_ORG, or the first
 * organization) may change plugins.
 */
final class PluginInstanceAdministrationTest extends TestCase
{
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    private Organization $home;

    private Organization $tenant;

    private User $homeAdmin;

    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        Http::preventStrayRequests();

        $this->home = Organization::create(['name' => 'Home', 'email' => 'home@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->tenant = Organization::create(['name' => 'Tenant', 'email' => 'tenant@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->homeAdmin = User::create([
            'name' => 'Home Admin', 'email' => 'admin@home.test', 'password' => bcrypt('password'),
            'organization_id' => $this->home->id, 'role' => 'admin',
        ]);
        $this->tenantAdmin = User::create([
            'name' => 'Tenant Admin', 'email' => 'admin@tenant.test', 'password' => bcrypt('password'),
            'organization_id' => $this->tenant->id, 'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        parent::tearDown();
    }

    public function test_another_organizations_admin_cannot_change_plugins(): void
    {
        config(['plugins.upload_enabled' => true]);
        $inactive = $this->makeFixturePlugin();
        $active = $this->makeFixturePlugin();
        app(PluginService::class)->activatePlugin($active);

        $this->actingAs($this->tenantAdmin);

        $this->post(route('plugins.activate', $inactive))->assertForbidden();
        $this->post(route('plugins.deactivate', $active))->assertForbidden();
        $this->delete(route('plugins.destroy', $inactive))->assertForbidden();
        $this->post(route('plugins.upload'), ['plugin' => UploadedFile::fake()->create('evil.zip', 1, 'application/zip')])->assertForbidden();
        $this->post(route('plugins.marketplace.install', 'some-plugin'))->assertForbidden();
        $this->post(route('plugins.marketplace.update', $active))->assertForbidden();

        $this->assertFalse((bool) Plugin::where('slug', $inactive)->value('is_active'));
        $this->assertTrue((bool) Plugin::where('slug', $active)->value('is_active'));
        $this->assertFileExists(base_path("plugins/{$inactive}/plugin.json"));
        Http::assertNothingSent();
    }

    public function test_the_first_organization_administers_plugins_by_default(): void
    {
        $slug = $this->makeFixturePlugin();

        $this->actingAs($this->homeAdmin)
            ->post(route('plugins.activate', $slug))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));
    }

    public function test_the_configured_organization_administers_plugins(): void
    {
        config(['plugins.admin_organization_id' => $this->tenant->id]);
        $slug = $this->makeFixturePlugin();

        $this->actingAs($this->homeAdmin)->post(route('plugins.activate', $slug))->assertForbidden();
        $this->assertFalse((bool) Plugin::where('slug', $slug)->value('is_active'));

        $this->actingAs($this->tenantAdmin)->post(route('plugins.activate', $slug));
        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));
    }

    public function test_the_plugins_page_says_whether_this_organization_administers_plugins(): void
    {
        $this->actingAs($this->homeAdmin)->get(route('plugins.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canAdministerPlugins', true));

        $this->actingAs($this->tenantAdmin)->get(route('plugins.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canAdministerPlugins', false));
    }
}
