<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\PluginService;
use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;
use ZipArchive;

/**
 * A plugin's `requires` (minimum Inventoros version) and `requires_php`
 * (minimum PHP version) are enforced on activation and on upload.
 */
final class PluginRequirementsTest extends TestCase
{
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        File::deleteDirectory(base_path('plugins/too-new-plugin'));
        parent::tearDown();
    }

    public function test_the_running_version_is_read_from_the_version_file(): void
    {
        $this->assertSame(trim(File::get(base_path('VERSION'))), AppVersion::current());
    }

    public function test_activation_is_refused_when_inventoros_is_too_old(): void
    {
        $slug = $this->makeFixturePlugin(['requires' => '99.0.0'], [
            'hooks/activate.php' => $this->recordingHook('activate'),
        ]);

        try {
            app(PluginService::class)->activatePlugin($slug);
            $this->fail('Activation should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('requires Inventoros 99.0.0 or newer', $e->getMessage());
            $this->assertStringContainsString(AppVersion::current(), $e->getMessage());
        }

        $this->assertFalse((bool) Plugin::where('slug', $slug)->value('is_active'));
        $this->assertSame([], $this->fixtureCalls());
    }

    public function test_activation_is_allowed_when_the_version_is_met(): void
    {
        $slug = $this->makeFixturePlugin(['requires' => AppVersion::current()]);
        app(PluginService::class)->activatePlugin($slug);
        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));

        $older = $this->makeFixturePlugin(['requires' => 'v0.1']);
        app(PluginService::class)->activatePlugin($older);
        $this->assertTrue((bool) Plugin::where('slug', $older)->value('is_active'));
    }

    public function test_activation_is_refused_when_php_is_too_old(): void
    {
        $slug = $this->makeFixturePlugin(['requires_php' => '99.0']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires PHP 99.0 or newer');

        app(PluginService::class)->activatePlugin($slug);
    }

    public function test_activation_is_refused_for_an_unreadable_version_constraint(): void
    {
        $slug = $this->makeFixturePlugin(['requires' => 'latest please']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid version');

        app(PluginService::class)->activatePlugin($slug);
    }

    public function test_a_manifest_without_requires_is_accepted(): void
    {
        $slug = $this->makeFixturePlugin();
        $manifestPath = base_path("plugins/{$slug}/plugin.json");
        $manifest = json_decode(File::get($manifestPath), true);
        unset($manifest['requires']);
        File::put($manifestPath, json_encode($manifest));

        app(PluginService::class)->activatePlugin($slug);

        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));
    }

    public function test_upload_is_refused_when_inventoros_is_too_old(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        config(['plugins.upload_enabled' => true]);

        $organization = Organization::create([
            'name' => 'Org', 'email' => 'org@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
            'organization_id' => $organization->id, 'role' => 'admin',
        ]);
        $role = Role::firstOrCreate(['slug' => 'system-administrator'], [
            'name' => 'Administrator', 'is_system' => true, 'permissions' => ['view_plugins', 'manage_plugins'],
        ]);
        $admin->roles()->syncWithoutDetaching([$role->id]);

        $zipPath = tempnam(sys_get_temp_dir(), 'inv-plugin-').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('too-new-plugin/plugin.json', json_encode([
            'name' => 'Too new', 'version' => '1.0.0', 'requires' => '99.0.0', 'main_file' => 'Plugin.php',
        ]));
        $zip->addFromString('too-new-plugin/Plugin.php', "<?php\n");
        $zip->close();

        $this->actingAs($admin)
            ->post(route('plugins.upload'), ['plugin' => new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true)])
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'requires Inventoros 99.0.0 or newer'));

        $this->assertDirectoryDoesNotExist(base_path('plugins/too-new-plugin'));
    }
}
