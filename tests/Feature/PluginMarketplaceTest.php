<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Marketplace\MarketplaceClient;
use App\Services\Marketplace\MarketplaceException;
use App\Services\Marketplace\PackageSignature;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;
use ZipArchive;

/**
 * One-click installs from the inventoros.com marketplace: the app downloads a
 * plugin ZIP server side, verifies its Ed25519 signature and sha256 against
 * the configured marketplace key and catalog, then installs it through the
 * same PluginService path as a manual upload.
 */
final class PluginMarketplaceTest extends TestCase
{
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    private const BASE = 'https://marketplace.test/api/v1/marketplace';

    private User $admin;

    private User $viewer;

    private Organization $organization;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Org', 'email' => 'org@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);

        $this->viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);

        $adminRole = Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Administrator', 'is_system' => true, 'permissions' => ['view_plugins', 'manage_plugins'],
        ]);
        $viewerRole = Role::firstOrCreate(['slug' => 'plugin-viewer'], [
            'name' => 'Plugin viewer', 'is_system' => false, 'permissions' => ['view_plugins'],
        ]);
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewer->roles()->syncWithoutDetaching([$viewerRole->id]);

        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);

        config([
            'marketplace.url' => 'https://marketplace.test',
            'marketplace.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'marketplace.cache_seconds' => 0,
            'plugins.upload_enabled' => false,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function slug(): string
    {
        $slug = 'mkt-'.strtolower(bin2hex(random_bytes(4)));
        $this->fixturePluginSlugs[] = $slug;

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    private function zipBytes(string $slug, array $manifest = [], array $files = [], ?string $root = null): string
    {
        $root ??= $slug;
        $path = tempnam(sys_get_temp_dir(), 'mkt-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString("{$root}/plugin.json", json_encode(array_merge([
            'name' => 'Market '.$slug,
            'description' => 'From the marketplace',
            'version' => '1.0.0',
            'author' => 'Tests',
            'requires' => '1.0.0',
            'main_file' => 'Plugin.php',
        ], $manifest)));
        $zip->addFromString("{$root}/Plugin.php", "<?php\n");
        foreach ($files as $name => $contents) {
            $zip->addFromString("{$root}/{$name}", $contents);
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * What the marketplace signs for a published version: the slug, version
     * and sha256 together, not just the ZIP bytes.
     */
    private function signPackage(string $slug, string $version, string $bytes): string
    {
        $message = PackageSignature::message($slug, $version, hash('sha256', $bytes));

        return base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(string $slug, string $bytes, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'name' => 'Market '.$slug,
            'summary' => 'A plugin',
            'version' => '1.0.0',
            'requires' => '1.0.0',
            'requires_php' => null,
            'pricing_type' => 'free',
            'price' => '0.00',
            'billing_interval' => null,
            'is_free' => true,
            'icon' => null,
            'homepage' => "https://marketplace.test/marketplace/{$slug}",
            'author' => 'Tests',
            'checksum' => hash('sha256', $bytes),
            'checksum_algorithm' => 'sha256',
            'size' => strlen($bytes),
            'published_at' => '2026-09-27T12:00:00Z',
            'has_access' => true,
        ], $overrides);
    }

    /**
     * Fake the marketplace for one plugin.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>|null  $headers  Download headers; null = a valid signed download.
     */
    private function fakeMarketplace(array $entry, string $bytes, ?array $headers = null, int $downloadStatus = 200): void
    {
        $headers ??= [
            'Content-Type' => 'application/zip',
            'X-Marketplace-Signature' => $this->signPackage((string) $entry['slug'], (string) $entry['version'], $bytes),
            'X-Marketplace-Checksum' => hash('sha256', $bytes),
            'X-Marketplace-Version' => $entry['version'],
        ];

        $slug = $entry['slug'];

        Http::fake([
            self::BASE.'/plugins' => Http::response(['data' => [$entry], 'meta' => ['authenticated' => false]]),
            self::BASE."/plugins/{$slug}" => Http::response(['data' => $entry + ['description' => 'Long', 'changelog' => null]]),
            self::BASE."/plugins/{$slug}/download" => $downloadStatus === 200
                ? Http::response($bytes, 200, $headers)
                : Http::response(['error' => $downloadStatus === 401 ? 'token_required' : 'no_access'], $downloadStatus),
            self::BASE.'/me' => Http::response(['data' => ['name' => 'Buyer', 'email' => 'buyer@example.com', 'owned' => [$slug]]]),
        ]);
    }

    private function markMarketplaceInstalled(string $slug): void
    {
        Plugin::updateOrCreate(['slug' => $slug], ['source' => Plugin::SOURCE_MARKETPLACE]);
    }

    private function install(string $slug, bool $activate = false)
    {
        return $this->actingAs($this->admin)
            ->from(route('plugins.marketplace'))
            ->post(route('plugins.marketplace.install', $slug), ['activate' => $activate]);
    }

    // ---------------------------------------------------------------- browse

    public function test_marketplace_tab_lists_the_catalog_with_installed_state(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['version' => '2.0.0']), $bytes);
        $this->makeFixturePlugin(['version' => '1.0.0'], [], $slug);
        $this->markMarketplaceInstalled($slug);

        $this->actingAs($this->admin)
            ->get(route('plugins.marketplace'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Plugins/Index')
                ->where('activeTab', 'marketplace')
                ->where('marketplace.configured', true)
                ->where('marketplace.connected', false)
                ->where('marketplace.plugins.0.slug', $slug)
                ->where('marketplace.plugins.0.installed_version', '1.0.0')
                ->where('marketplace.plugins.0.update_available', true)
            );
    }

    public function test_without_a_signing_key_the_marketplace_tab_tells_admins_installs_are_off(): void
    {
        config(['marketplace.public_key' => '']);
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        // The catalog still lists, but the page is told installs are off so it
        // shows the notice (plugins.marketplace.notConfigured) instead of
        // install buttons that could only fail.
        $this->actingAs($this->admin)
            ->get(route('plugins.marketplace'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('marketplace.configured', false)
                ->where('marketplace.plugins.0.slug', $slug)
            );

        $messages = json_decode((string) file_get_contents(resource_path('js/i18n/locales/en.json')), true);
        $this->assertStringContainsString('INVENTOROS_MARKETPLACE_PUBLIC_KEY', $messages['plugins']['marketplace']['notConfigured']);

        $page = (string) file_get_contents(resource_path('js/Pages/Plugins/Index.vue'));
        $this->assertStringContainsString("v-if=\"!marketplace.configured\"", $page);
        $this->assertStringContainsString("t('plugins.marketplace.notConfigured')", $page);
        $this->assertStringContainsString('marketplace.configured && item.has_access', $page);
    }

    public function test_an_unreachable_marketplace_shows_an_error_instead_of_failing(): void
    {
        Http::fake([self::BASE.'/plugins' => Http::response('down', 500)]);

        $this->actingAs($this->admin)
            ->get(route('plugins.marketplace'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('marketplace.plugins', [])
                ->where('marketplace.error', fn ($error) => str_contains((string) $error, 'marketplace'))
            );
    }

    public function test_a_viewer_can_browse_but_not_install(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->actingAs($this->viewer)->get(route('plugins.marketplace'))->assertOk();

        $this->actingAs($this->viewer)
            ->post(route('plugins.marketplace.install', $slug))
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->post(route('plugins.marketplace.update', $slug))
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->post(route('plugins.marketplace.connect'), ['token' => 'abc'])
            ->assertForbidden();

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    // ---------------------------------------------------------------- install

    public function test_a_signed_free_plugin_installs_even_with_uploads_disabled(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->install($slug)->assertSessionHas('success');

        $this->assertFileExists(base_path("plugins/{$slug}/plugin.json"));
        $this->assertFalse((bool) Plugin::where('slug', $slug)->value('is_active'));
    }

    public function test_install_and_activate_publishes_runtime_assets(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug, ['ui' => ['entry' => 'plugin.js', 'styles' => ['plugin.css']]], [
            'dist/plugin.js' => "export default 1;\n",
            'dist/plugin.css' => ".x{}\n",
        ]);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->install($slug, activate: true)->assertSessionHas('success');

        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));
        $this->assertFileExists(public_path("plugin-assets/{$slug}/plugin.js"));
    }

    public function test_an_invalid_signature_is_rejected_before_anything_is_written(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $message = PackageSignature::message($slug, '1.0.0', hash('sha256', $bytes));
        $forged = base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes, [
            'X-Marketplace-Signature' => $forged,
            'X-Marketplace-Checksum' => hash('sha256', $bytes),
            'X-Marketplace-Version' => '1.0.0',
        ]);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'signature'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes, [
            'X-Marketplace-Checksum' => hash('sha256', $bytes),
        ]);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'not signed'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    public function test_a_signed_zip_whose_checksum_does_not_match_the_catalog_is_rejected(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['checksum' => str_repeat('a', 64)]), $bytes);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'checksum'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    public function test_a_checksum_header_mismatch_is_rejected(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes, [
            'X-Marketplace-Signature' => $this->signPackage($slug, '1.0.0', $bytes),
            'X-Marketplace-Checksum' => str_repeat('b', 64),
            'X-Marketplace-Version' => '1.0.0',
        ]);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'checksum'));
        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    public function test_installs_are_refused_without_a_configured_public_key(): void
    {
        config(['marketplace.public_key' => '']);
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'INVENTOROS_MARKETPLACE_PUBLIC_KEY'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/download'));
    }

    public function test_a_plugin_requiring_a_newer_inventoros_is_refused(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug, ['requires' => '99.0.0']);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['requires' => '99.0.0']), $bytes);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'requires Inventoros 99.0.0'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    public function test_a_zip_whose_folder_is_not_the_requested_slug_is_refused(): void
    {
        $slug = $this->slug();
        $other = $this->slug();
        $bytes = $this->zipBytes($slug, root: $other);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, $other));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
        $this->assertDirectoryDoesNotExist(base_path("plugins/{$other}"));
    }

    public function test_a_paid_plugin_without_a_connected_account_gives_a_clear_error(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes, [
            'pricing_type' => 'one_time', 'price' => '29.00', 'is_free' => false, 'has_access' => false,
        ]), $bytes, downloadStatus: 401);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'paid plugin') && str_contains($e, 'Connect'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/download'));
    }

    public function test_a_paid_plugin_the_connected_account_owns_downloads_with_the_token(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->organization->forceFill(['marketplace_token' => 'tok_123'])->save();
        $this->fakeMarketplace($this->entry($slug, $bytes, [
            'pricing_type' => 'one_time', 'price' => '29.00', 'is_free' => false, 'has_access' => true,
        ]), $bytes);

        $this->install($slug)->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/download')
            && $r->hasHeader('Authorization', 'Bearer tok_123'));
    }

    public function test_installing_an_installed_plugin_points_to_update(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);
        $this->makeFixturePlugin([], [], $slug);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'already installed'));
    }

    // ---------------------------------------------------------------- update

    public function test_update_replaces_files_keeps_data_and_stays_active(): void
    {
        $slug = $this->slug();
        $this->makeFixturePlugin(['version' => '1.0.0'], [
            'hooks/uninstall.php' => $this->recordingHook('uninstall'),
            'hooks/deactivate.php' => $this->recordingHook('deactivate'),
            'old-only.txt' => 'old',
        ], $slug);
        $this->markMarketplaceInstalled($slug);
        app(PluginService::class)->activatePlugin($slug);
        $pluginId = Plugin::where('slug', $slug)->value('id');

        $bytes = $this->zipBytes($slug, ['version' => '2.0.0'], ['hooks/activate.php' => $this->recordingHook('activate-v2')]);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['version' => '2.0.0']), $bytes);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.update', $slug))
            ->assertSessionHas('success');

        $manifest = json_decode((string) file_get_contents(base_path("plugins/{$slug}/plugin.json")), true);
        $this->assertSame('2.0.0', $manifest['version']);
        $this->assertFileDoesNotExist(base_path("plugins/{$slug}/old-only.txt"));
        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));
        $this->assertSame($pluginId, Plugin::where('slug', $slug)->value('id'));
        $this->assertSame(['deactivate', 'activate-v2'], $this->fixtureCalls());
    }

    public function test_a_failed_update_restores_the_previous_version(): void
    {
        $slug = $this->slug();
        $this->makeFixturePlugin(['version' => '1.0.0'], [], $slug);
        $this->markMarketplaceInstalled($slug);
        app(PluginService::class)->activatePlugin($slug);

        $bytes = $this->zipBytes($slug, ['version' => '2.0.0'], ['hooks/activate.php' => $this->recordingHook('activate-v2', throws: true)]);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['version' => '2.0.0']), $bytes);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.update', $slug))
            ->assertSessionHas('error');

        $manifest = json_decode((string) file_get_contents(base_path("plugins/{$slug}/plugin.json")), true);
        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));
    }

    public function test_update_with_a_bad_signature_leaves_the_installed_version_alone(): void
    {
        $slug = $this->slug();
        $this->makeFixturePlugin(['version' => '1.0.0'], [], $slug);
        $this->markMarketplaceInstalled($slug);

        $bytes = $this->zipBytes($slug, ['version' => '2.0.0']);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['version' => '2.0.0']), $bytes, [
            'X-Marketplace-Signature' => base64_encode(str_repeat("\0", 64)),
            'X-Marketplace-Checksum' => hash('sha256', $bytes),
            'X-Marketplace-Version' => '2.0.0',
        ]);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.update', $slug))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'signature'));

        $manifest = json_decode((string) file_get_contents(base_path("plugins/{$slug}/plugin.json")), true);
        $this->assertSame('1.0.0', $manifest['version']);
    }

    public function test_update_is_refused_when_already_current(): void
    {
        $slug = $this->slug();
        $this->makeFixturePlugin(['version' => '1.0.0'], [], $slug);
        $this->markMarketplaceInstalled($slug);
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.update', $slug))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'up to date'));

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/download'));
    }

    // ---------------------------------------------------------------- install source

    public function test_install_records_that_the_plugin_came_from_the_marketplace(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->install($slug)->assertSessionHas('success');

        $this->assertSame(Plugin::SOURCE_MARKETPLACE, Plugin::where('slug', $slug)->value('source'));
    }

    public function test_update_refuses_a_same_named_plugin_that_was_not_installed_from_the_marketplace(): void
    {
        $slug = $this->slug();
        $this->makeFixturePlugin(['version' => '1.0.0'], ['local-only.txt' => 'mine'], $slug);

        $bytes = $this->zipBytes($slug, ['version' => '2.0.0']);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['version' => '2.0.0']), $bytes);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.update', $slug))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not installed from the marketplace'));

        $this->assertFileExists(base_path("plugins/{$slug}/local-only.txt"));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/download'));
    }

    public function test_the_marketplace_tab_does_not_offer_updates_for_local_plugins(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['version' => '2.0.0']), $bytes);
        $this->makeFixturePlugin(['version' => '1.0.0'], [], $slug);

        $this->actingAs($this->admin)
            ->get(route('plugins.marketplace'))
            ->assertInertia(fn ($page) => $page
                ->where('marketplace.plugins.0.installed_version', '1.0.0')
                ->where('marketplace.plugins.0.update_available', false)
                ->where('marketplace.plugins.0.installed_from_marketplace', false)
            );
    }

    // ---------------------------------------------------------------- catalog links and download size

    public function test_marketplace_icons_must_use_https(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['icon' => 'http://cdn.example.com/icon.png']), $bytes);

        $this->actingAs($this->admin)
            ->get(route('plugins.marketplace'))
            ->assertInertia(fn ($page) => $page->where('marketplace.plugins.0.icon', null));
    }

    public function test_an_https_marketplace_icon_is_kept(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['icon' => 'https://cdn.example.com/icon.png']), $bytes);

        $this->actingAs($this->admin)
            ->get(route('plugins.marketplace'))
            ->assertInertia(fn ($page) => $page->where('marketplace.plugins.0.icon', 'https://cdn.example.com/icon.png'));
    }

    public function test_an_oversized_download_without_a_content_length_is_refused_and_not_kept(): void
    {
        config(['marketplace.max_download_bytes' => 100]);
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->assertGreaterThan(100, strlen($bytes));
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'size limit'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
        $this->assertSame([], glob(storage_path('app/marketplace-downloads/*')) ?: []);
    }

    public function test_the_download_is_streamed_to_disk_with_a_byte_cap(): void
    {
        // The body goes to a temp file (sink) and a progress callback aborts
        // the transfer past the cap, so an endless or lying response is never
        // buffered whole in memory.
        $options = app(MarketplaceClient::class)->downloadOptions('/tmp/x.zip', 100);

        $this->assertSame('/tmp/x.zip', $options['sink']);
        $this->assertIsCallable($options['progress']);
        $this->assertIsCallable($options['on_headers']);

        $options['progress'](0, 100);
        $this->expectException(\RuntimeException::class);
        $options['progress'](0, 101);
    }

    // ---------------------------------------------------------------- signed manifest (downgrade / substitution)

    public function test_an_older_signed_package_cannot_be_replayed_as_an_update(): void
    {
        $slug = $this->slug();
        $this->makeFixturePlugin(['version' => '2.0.0'], [], $slug);
        $this->markMarketplaceInstalled($slug);

        // A genuine, marketplace-signed 1.0.0 package from an earlier release,
        // served while the (unsigned) catalog claims 3.0.0.
        $old = $this->zipBytes($slug, ['version' => '1.0.0']);
        $this->fakeMarketplace($this->entry($slug, $old, ['version' => '3.0.0']), $old, [
            'X-Marketplace-Signature' => $this->signPackage($slug, '1.0.0', $old),
            'X-Marketplace-Checksum' => hash('sha256', $old),
            'X-Marketplace-Version' => '3.0.0',
        ]);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.update', $slug))
            ->assertSessionHas('error');

        $manifest = json_decode((string) file_get_contents(base_path("plugins/{$slug}/plugin.json")), true);
        $this->assertSame('2.0.0', $manifest['version']);
    }

    public function test_an_update_whose_signed_version_is_not_newer_is_refused(): void
    {
        $slug = $this->slug();
        $this->makeFixturePlugin(['version' => '2.0.0'], [], $slug);
        $this->markMarketplaceInstalled($slug);

        // Everything is consistent and genuinely signed, but it is 1.0.0: the
        // listing is what lies about being newer.
        $old = $this->zipBytes($slug, ['version' => '1.0.0']);
        $this->fakeMarketplace($this->entry($slug, $old, ['version' => '3.0.0']), $old, [
            'X-Marketplace-Signature' => $this->signPackage($slug, '1.0.0', $old),
            'X-Marketplace-Checksum' => hash('sha256', $old),
            'X-Marketplace-Version' => '1.0.0',
        ]);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.update', $slug))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'version'));

        $manifest = json_decode((string) file_get_contents(base_path("plugins/{$slug}/plugin.json")), true);
        $this->assertSame('2.0.0', $manifest['version']);
    }

    public function test_a_package_whose_plugin_json_version_differs_from_the_signed_version_is_refused(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug, ['version' => '0.9.0']);
        $this->fakeMarketplace($this->entry($slug, $bytes, ['version' => '1.0.0']), $bytes, [
            'X-Marketplace-Signature' => $this->signPackage($slug, '1.0.0', $bytes),
            'X-Marketplace-Checksum' => hash('sha256', $bytes),
            'X-Marketplace-Version' => '1.0.0',
        ]);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, '0.9.0'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    public function test_a_signature_made_for_another_plugin_is_refused(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes, [
            'X-Marketplace-Signature' => $this->signPackage('some-other-plugin', '1.0.0', $bytes),
            'X-Marketplace-Checksum' => hash('sha256', $bytes),
            'X-Marketplace-Version' => '1.0.0',
        ]);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'signature'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    public function test_a_bare_zip_signature_is_no_longer_accepted(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes, [
            'X-Marketplace-Signature' => base64_encode(sodium_crypto_sign_detached($bytes, $this->secretKey)),
            'X-Marketplace-Checksum' => hash('sha256', $bytes),
            'X-Marketplace-Version' => '1.0.0',
        ]);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'signature'));

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    public function test_the_signed_statement_format_matches_the_marketplace(): void
    {
        // inventoros.com signs these exact bytes (tests/Pest.php isSignedPackage
        // on the site spells out the same literal). Changing it breaks installs.
        $this->assertSame(
            "inventoros-marketplace-package-v1\nhello-world\n1.3.0\n".str_repeat('ab', 32)."\n",
            PackageSignature::message('hello-world', '1.3.0', str_repeat('AB', 32)),
        );
    }

    public function test_a_download_without_a_version_header_is_refused(): void
    {
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes, [
            'X-Marketplace-Signature' => $this->signPackage($slug, '1.0.0', $bytes),
            'X-Marketplace-Checksum' => hash('sha256', $bytes),
        ]);

        $this->install($slug)->assertSessionHas('error');

        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }

    // ---------------------------------------------------------------- account

    public function test_connecting_an_account_validates_and_stores_the_token_encrypted(): void
    {
        Http::fake([self::BASE.'/me' => Http::response(['data' => ['name' => 'Buyer', 'email' => 'buyer@example.com', 'owned' => []]])]);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.connect'), ['token' => '12|secret-token'])
            ->assertSessionHas('success');

        $org = $this->organization->fresh();
        $this->assertSame('12|secret-token', $org->marketplace_token);
        $raw = \DB::table('organizations')->where('id', $org->id)->value('marketplace_token');
        $this->assertNotSame('12|secret-token', $raw);
        $this->assertStringNotContainsString('secret-token', (string) $raw);
        $this->assertSame('buyer@example.com', $org->marketplace_account['email']);
        $this->assertArrayNotHasKey('marketplace_token', $org->toArray());

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer 12|secret-token'));
    }

    public function test_a_rejected_token_is_not_stored(): void
    {
        Http::fake([self::BASE.'/me' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs($this->admin)
            ->post(route('plugins.marketplace.connect'), ['token' => 'bad'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'rejected'));

        $this->assertNull($this->organization->fresh()->marketplace_token);
    }

    public function test_disconnecting_forgets_the_token(): void
    {
        $this->organization->forceFill(['marketplace_token' => 'tok', 'marketplace_account' => ['name' => 'x', 'email' => 'x@example.com']])->save();

        $this->actingAs($this->admin)
            ->delete(route('plugins.marketplace.disconnect'))
            ->assertSessionHas('success');

        $this->assertNull($this->organization->fresh()->marketplace_token);
        $this->assertNull($this->organization->fresh()->marketplace_account);
    }

    // ---------------------------------------------------------------- SSRF

    public function test_a_non_https_marketplace_url_is_refused(): void
    {
        config(['marketplace.url' => 'http://marketplace.test']);

        $this->expectException(MarketplaceException::class);
        app(MarketplaceClient::class)->catalog(null);
    }

    public function test_a_marketplace_url_with_credentials_or_a_path_is_refused(): void
    {
        foreach (['https://user:pass@marketplace.test', 'https://marketplace.test/evil?x=1', 'file:///etc/passwd', 'https://'] as $url) {
            config(['marketplace.url' => $url]);

            try {
                app(MarketplaceClient::class)->catalog(null);
                $this->fail("{$url} should have been refused.");
            } catch (MarketplaceException $e) {
                $this->assertStringContainsString('INVENTOROS_MARKETPLACE_URL', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_redirects_are_not_followed_off_the_marketplace_host(): void
    {
        Http::fake([
            self::BASE.'/plugins' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
            '169.254.169.254/*' => Http::response(['data' => []]),
        ]);

        try {
            app(MarketplaceClient::class)->catalog(null);
            $this->fail('A redirect response should not be treated as a catalog.');
        } catch (MarketplaceException) {
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254.169.254'));
    }

    public function test_an_unsafe_slug_never_reaches_the_network(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->post('/plugins/marketplace/..%2F..%2Fetc/install')
            ->assertNotFound();

        $this->expectException(MarketplaceException::class);
        app(MarketplaceClient::class)->plugin('../me', null);
    }

    public function test_an_oversized_download_is_refused(): void
    {
        config(['marketplace.max_download_bytes' => 100]);
        $slug = $this->slug();
        $bytes = $this->zipBytes($slug);
        $this->fakeMarketplace($this->entry($slug, $bytes), $bytes);

        $this->install($slug)->assertSessionHas('error', fn ($e) => str_contains($e, 'size limit'));
        $this->assertDirectoryDoesNotExist(base_path("plugins/{$slug}"));
    }
}
