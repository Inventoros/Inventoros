<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PluginHookFailed;
use App\Models\Plugin;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * hooks/activate.php, hooks/deactivate.php and hooks/uninstall.php are part of
 * the documented plugin format; the service runs them at the matching point in
 * the lifecycle, and a failing activation leaves the plugin inactive.
 */
final class PluginLifecycleTest extends TestCase
{
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        parent::tearDown();
    }

    private function service(): PluginService
    {
        return app(PluginService::class);
    }

    private function isActive(string $slug): bool
    {
        return (bool) Plugin::where('slug', $slug)->value('is_active');
    }

    public function test_activation_runs_the_activate_hook_and_marks_the_plugin_active(): void
    {
        $slug = $this->makeFixturePlugin(files: ['hooks/activate.php' => $this->recordingHook('activate')]);

        $this->service()->activatePlugin($slug);

        $this->assertSame(['activate'], $this->fixtureCalls());
        $this->assertTrue($this->isActive($slug));
    }

    public function test_activating_an_already_active_plugin_does_not_rerun_the_hook(): void
    {
        $slug = $this->makeFixturePlugin(files: ['hooks/activate.php' => $this->recordingHook('activate')]);

        $this->service()->activatePlugin($slug);
        $this->service()->activatePlugin($slug);

        $this->assertSame(['activate'], $this->fixtureCalls());
    }

    public function test_a_failing_activate_hook_leaves_the_plugin_inactive(): void
    {
        $slug = $this->makeFixturePlugin(files: ['hooks/activate.php' => $this->recordingHook('activate', throws: true)]);

        $fired = false;
        add_action('plugin_activated', function () use (&$fired) {
            $fired = true;
        });

        try {
            $this->service()->activatePlugin($slug);
            $this->fail('Activation should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('activate exploded', $e->getMessage());
        }

        $this->assertFalse($this->isActive($slug));
        $this->assertFalse($fired, 'plugin_activated must not fire for a failed activation.');
    }

    public function test_a_main_file_that_throws_leaves_the_plugin_inactive(): void
    {
        $slug = $this->makeFixturePlugin(files: [
            'Plugin.php' => "<?php\nthrow new \\RuntimeException('main file exploded');\n",
            'hooks/activate.php' => $this->recordingHook('activate'),
        ]);

        try {
            $this->service()->activatePlugin($slug);
            $this->fail('Activation should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('main file exploded', $e->getMessage());
        }

        $this->assertFalse($this->isActive($slug));
        $this->assertSame([], $this->fixtureCalls(), 'activate.php must not run when the plugin cannot load.');
    }

    public function test_activating_a_plugin_that_is_not_installed_fails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not installed');

        $this->service()->activatePlugin('no-such-plugin');
    }

    public function test_deactivation_runs_the_deactivate_hook(): void
    {
        $slug = $this->makeFixturePlugin(files: ['hooks/deactivate.php' => $this->recordingHook('deactivate')]);
        $this->service()->activatePlugin($slug);

        $this->service()->deactivatePlugin($slug);

        $this->assertSame(['deactivate'], $this->fixtureCalls());
        $this->assertFalse($this->isActive($slug));
    }

    public function test_a_failing_deactivate_hook_still_deactivates_and_reports_it(): void
    {
        $slug = $this->makeFixturePlugin(files: ['hooks/deactivate.php' => $this->recordingHook('deactivate', throws: true)]);
        $this->service()->activatePlugin($slug);

        try {
            $this->service()->deactivatePlugin($slug);
            $this->fail('The hook failure should be reported.');
        } catch (PluginHookFailed $e) {
            $this->assertStringContainsString('deactivate exploded', $e->getMessage());
        }

        $this->assertFalse($this->isActive($slug));
    }

    public function test_deleting_an_active_plugin_runs_deactivate_then_uninstall_and_removes_it(): void
    {
        $slug = $this->makeFixturePlugin(files: [
            'hooks/deactivate.php' => $this->recordingHook('deactivate'),
            'hooks/uninstall.php' => $this->recordingHook('uninstall'),
        ]);
        $this->service()->activatePlugin($slug);

        $this->assertTrue($this->service()->deletePlugin($slug));

        $this->assertSame(['deactivate', 'uninstall'], $this->fixtureCalls());
        $this->assertDirectoryDoesNotExist(base_path('plugins/'.$slug));
        $this->assertNull(Plugin::where('slug', $slug)->first());
    }

    public function test_deleting_an_inactive_plugin_runs_the_uninstall_hook(): void
    {
        $slug = $this->makeFixturePlugin(files: ['hooks/uninstall.php' => $this->recordingHook('uninstall')]);

        $this->service()->deletePlugin($slug);

        $this->assertSame(['uninstall'], $this->fixtureCalls());
        $this->assertDirectoryDoesNotExist(base_path('plugins/'.$slug));
    }

    public function test_a_failing_uninstall_hook_still_removes_the_plugin_and_reports_it(): void
    {
        $slug = $this->makeFixturePlugin(files: ['hooks/uninstall.php' => $this->recordingHook('uninstall', throws: true)]);

        try {
            $this->service()->deletePlugin($slug);
            $this->fail('The hook failure should be reported.');
        } catch (PluginHookFailed $e) {
            $this->assertStringContainsString('uninstall exploded', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist(base_path('plugins/'.$slug));
    }

    public function test_lifecycle_files_cannot_reach_the_service_instance(): void
    {
        $slug = $this->makeFixturePlugin(files: [
            'hooks/activate.php' => "<?php\n\$GLOBALS['fixture_plugin_calls'][] = isset(\$this) ? 'has-this' : 'isolated';\n",
        ]);

        $this->service()->activatePlugin($slug);

        $this->assertSame(['isolated'], $this->fixtureCalls());
    }

    public function test_plugins_without_lifecycle_files_still_activate_and_delete(): void
    {
        $slug = $this->makeFixturePlugin();

        $this->service()->activatePlugin($slug);
        $this->assertTrue($this->isActive($slug));

        $this->service()->deactivatePlugin($slug);
        $this->assertFalse($this->isActive($slug));

        $this->assertTrue($this->service()->deletePlugin($slug));
        $this->assertFalse(File::isDirectory(base_path('plugins/'.$slug)));
    }
}
