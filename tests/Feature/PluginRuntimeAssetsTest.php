<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Plugin;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\PluginService;
use App\Services\Plugins\PluginAssetPublisher;
use App\Services\Plugins\PluginRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * Runtime plugin UI: a plugin ships a pre-built ES module in dist/, which is
 * published to public/plugins/{slug}/ while the plugin is active and listed in
 * the shared Inertia props so the browser can import() it. No npm build of the
 * host application is involved, so a ZIP-uploaded plugin gets its UI too.
 */
final class PluginRuntimeAssetsTest extends TestCase
{
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        parent::tearDown();
    }

    private function makeRuntimePlugin(array $ui = ['entry' => 'plugin.js', 'styles' => ['plugin.css']], array $extraFiles = []): string
    {
        return $this->makeFixturePlugin(['ui' => $ui], array_merge([
            'dist/plugin.js' => "export default 1;\n",
            'dist/plugin.css' => ".fixture{}\n",
            'dist/chunks/extra.js' => "export const x = 1;\n",
        ], $extraFiles));
    }

    private function actingUser(): User
    {
        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::create([
            'name' => 'Org', 'email' => 'org@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $organization->id, 'role' => 'admin',
        ]);
    }

    public function test_activation_publishes_the_dist_assets(): void
    {
        $slug = $this->makeRuntimePlugin();

        app(PluginService::class)->activatePlugin($slug);

        $this->assertFileExists(public_path("plugins/{$slug}/plugin.js"));
        $this->assertFileExists(public_path("plugins/{$slug}/plugin.css"));
        $this->assertFileExists(public_path("plugins/{$slug}/chunks/extra.js"));
    }

    public function test_only_static_web_assets_are_published(): void
    {
        $slug = $this->makeRuntimePlugin(extraFiles: [
            'dist/shell.php' => "<?php echo 'pwned';\n",
            'dist/.htaccess' => "SetHandler application/x-httpd-php\n",
            'dist/notes.phtml' => "<?php\n",
        ]);

        app(PluginService::class)->activatePlugin($slug);

        $this->assertFileExists(public_path("plugins/{$slug}/plugin.js"));
        $this->assertFileDoesNotExist(public_path("plugins/{$slug}/shell.php"));
        $this->assertFileDoesNotExist(public_path("plugins/{$slug}/.htaccess"));
        $this->assertFileDoesNotExist(public_path("plugins/{$slug}/notes.phtml"));
    }

    public function test_deactivation_removes_the_published_assets(): void
    {
        $slug = $this->makeRuntimePlugin();
        app(PluginService::class)->activatePlugin($slug);

        app(PluginService::class)->deactivatePlugin($slug);

        $this->assertDirectoryDoesNotExist(public_path("plugins/{$slug}"));
    }

    public function test_deletion_removes_the_published_assets(): void
    {
        $slug = $this->makeRuntimePlugin();
        app(PluginService::class)->activatePlugin($slug);

        app(PluginService::class)->deletePlugin($slug);

        $this->assertDirectoryDoesNotExist(public_path("plugins/{$slug}"));
    }

    public function test_an_entry_that_escapes_dist_is_rejected_and_the_plugin_stays_inactive(): void
    {
        foreach (['../Plugin.php', '../../../.env', '/etc/passwd', 'C:\\Windows\\win.ini', 'missing.js', 'plugin.php'] as $entry) {
            $slug = $this->makeRuntimePlugin(['entry' => $entry]);

            try {
                app(PluginService::class)->activatePlugin($slug);
                $this->fail("Entry {$entry} should have been rejected.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('ui', $e->getMessage());
            }

            $this->assertFalse((bool) Plugin::where('slug', $slug)->value('is_active'));
            $this->assertDirectoryDoesNotExist(public_path("plugins/{$slug}"));
        }
    }

    public function test_a_style_that_escapes_dist_is_rejected(): void
    {
        $slug = $this->makeRuntimePlugin(['entry' => 'plugin.js', 'styles' => ['../plugin.json']]);

        $this->expectException(\RuntimeException::class);

        app(PluginService::class)->activatePlugin($slug);
    }

    public function test_shared_props_list_active_runtime_plugin_entrypoints(): void
    {
        $user = $this->actingUser();
        $active = $this->makeRuntimePlugin();
        $inactive = $this->makeRuntimePlugin();
        $serverOnly = $this->makeFixturePlugin();
        app(PluginService::class)->activatePlugin($active);
        app(PluginService::class)->activatePlugin($serverOnly);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pluginAssets', 1)
                ->where('pluginAssets.0.slug', $active)
                ->where('pluginAssets.0.entry', url("plugins/{$active}/plugin.js").'?v=1.2.3')
                ->where('pluginAssets.0.styles', [url("plugins/{$active}/plugin.css").'?v=1.2.3']));

        $this->assertNotSame($inactive, $active);
    }

    public function test_missing_published_assets_are_republished_for_active_plugins(): void
    {
        $user = $this->actingUser();
        $slug = $this->makeRuntimePlugin();
        app(PluginService::class)->activatePlugin($slug);

        // An in-place update can replace public/ and drop published plugin files.
        File::deleteDirectory(public_path("plugins/{$slug}"));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->has('pluginAssets', 1));

        $this->assertFileExists(public_path("plugins/{$slug}/plugin.js"));
    }

    public function test_an_updated_plugin_version_republishes_its_assets(): void
    {
        $user = $this->actingUser();
        $slug = $this->makeRuntimePlugin();
        app(PluginService::class)->activatePlugin($slug);

        // The plugin folder is replaced in place with a newer build.
        File::put(base_path("plugins/{$slug}/dist/plugin.js"), "export default 2;\n");
        $manifestPath = base_path("plugins/{$slug}/plugin.json");
        File::put($manifestPath, json_encode(array_merge(json_decode(File::get($manifestPath), true), ['version' => '1.3.0'])));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('pluginAssets.0.entry', url("plugins/{$slug}/plugin.js").'?v=1.3.0'));

        $this->assertSame("export default 2;\n", File::get(public_path("plugins/{$slug}/plugin.js")));
    }

    public function test_guests_receive_no_plugin_assets(): void
    {
        $this->actingUser();
        $slug = $this->makeRuntimePlugin();
        app(PluginService::class)->activatePlugin($slug);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pluginAssets', []));
    }

    public function test_the_shipped_hello_world_bundle_is_valid_and_uses_the_host_vue(): void
    {
        $manifest = json_decode(File::get(base_path('plugins/hello-world/plugin.json')), true);

        PluginRequirements::assertMet($manifest, 'Hello World');
        $ui = PluginAssetPublisher::make()->uiFor('hello-world', $manifest);

        $this->assertSame('plugin.js', $ui['entry']);
        $bundle = File::get(base_path('plugins/hello-world/dist/plugin.js'));

        // A bare "vue" import cannot resolve in the browser; the build must
        // read the app's copy from window.Inventoros instead of bundling one.
        $this->assertDoesNotMatchRegularExpression('/from\s*["\']vue["\']/', $bundle);
        $this->assertStringContainsString('window.Inventoros.Vue', $bundle);
        $this->assertStringContainsString('HelloWorldBanner', $bundle);
    }

    public function test_csp_lets_the_browser_import_same_origin_plugin_modules(): void
    {
        $this->actingUser();

        $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+';/", $csp);
        // 'strict-dynamic' would make browsers ignore 'self' and block import().
        $this->assertStringNotContainsString('strict-dynamic', $csp);
        $this->assertStringContainsString("style-src 'self'", $csp);
    }
}
