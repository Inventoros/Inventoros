<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * plugin:activate / plugin:deactivate run the same lifecycle as the Plugins
 * page, for servers managed over SSH and for the end-to-end suite.
 */
final class PluginCommandsTest extends TestCase
{
    use InteractsWithFixturePlugins;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        parent::tearDown();
    }

    public function test_activate_and_deactivate_a_plugin(): void
    {
        $slug = $this->makeFixturePlugin(files: ['hooks/activate.php' => $this->recordingHook('activate')]);

        $this->artisan('plugin:activate', ['slug' => $slug])
            ->expectsOutputToContain('activated')
            ->assertSuccessful();
        $this->assertTrue((bool) Plugin::where('slug', $slug)->value('is_active'));
        $this->assertSame(['activate'], $this->fixtureCalls());

        $this->artisan('plugin:deactivate', ['slug' => $slug])->assertSuccessful();
        $this->assertFalse((bool) Plugin::where('slug', $slug)->value('is_active'));
    }

    public function test_a_failed_activation_exits_with_an_error(): void
    {
        $slug = $this->makeFixturePlugin(['requires' => '99.0.0']);

        $this->artisan('plugin:activate', ['slug' => $slug])
            ->expectsOutputToContain('requires Inventoros 99.0.0')
            ->assertFailed();
    }

    public function test_an_unknown_plugin_exits_with_an_error(): void
    {
        $this->artisan('plugin:activate', ['slug' => 'no-such-plugin'])->assertFailed();
        $this->artisan('plugin:activate', ['slug' => '../etc'])->assertFailed();
    }
}
