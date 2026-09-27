<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Builds throwaway plugins under /plugins for lifecycle tests and removes
 * them (and anything they published under public/plugins) afterwards.
 *
 * Lifecycle files record that they ran in $GLOBALS['fixture_plugin_calls'].
 */
trait InteractsWithFixturePlugins
{
    /** @var array<int, string> */
    protected array $fixturePluginSlugs = [];

    protected function tearDownFixturePlugins(): void
    {
        foreach ($this->fixturePluginSlugs as $slug) {
            File::deleteDirectory(base_path('plugins/'.$slug));
            File::deleteDirectory(public_path('plugins/'.$slug));
        }

        $this->fixturePluginSlugs = [];
        unset($GLOBALS['fixture_plugin_calls']);
    }

    /**
     * @param  array<string, mixed>  $manifest  Merged over a valid default manifest.
     * @param  array<string, string>  $files  Extra files, path relative to the plugin root => contents.
     */
    protected function makeFixturePlugin(array $manifest = [], array $files = [], ?string $slug = null): string
    {
        $slug ??= 'fixture-'.Str::lower(Str::random(10));
        $this->fixturePluginSlugs[] = $slug;

        $root = base_path('plugins/'.$slug);
        File::ensureDirectoryExists($root);

        File::put($root.'/plugin.json', json_encode(array_merge([
            'name' => 'Fixture '.$slug,
            'description' => 'Test fixture',
            'version' => '1.2.3',
            'author' => 'Tests',
            'requires' => '1.0.0',
            'main_file' => 'Plugin.php',
        ], $manifest), JSON_PRETTY_PRINT));

        $files += ['Plugin.php' => "<?php\n"];

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            File::put($root.'/'.$path, $contents);
        }

        return $slug;
    }

    /**
     * A lifecycle file that records it ran, optionally throwing afterwards.
     */
    protected function recordingHook(string $name, bool $throws = false): string
    {
        $throw = $throws ? "throw new \\RuntimeException('{$name} exploded');" : '';

        return "<?php\n\$GLOBALS['fixture_plugin_calls'][] = '{$name}';\n{$throw}\n";
    }

    /**
     * @return array<int, string>
     */
    protected function fixtureCalls(): array
    {
        return $GLOBALS['fixture_plugin_calls'] ?? [];
    }
}
