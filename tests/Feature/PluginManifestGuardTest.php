<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PluginManifestGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_all_plugins_skips_malformed_manifest(): void
    {
        $dir = base_path('plugins/broken-manifest-test');
        File::makeDirectory($dir, 0755, true, true);
        File::put($dir.'/plugin.json', '"just a string"'); // valid JSON, not an object

        try {
            $plugins = app(PluginService::class)->getAllPlugins();
            $slugs = array_column($plugins, 'slug');
            $this->assertNotContains('broken-manifest-test', $slugs);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_get_all_plugins_includes_valid_manifest(): void
    {
        $dir = base_path('plugins/valid-manifest-test');
        File::makeDirectory($dir, 0755, true, true);
        File::put($dir.'/plugin.json', json_encode(['name' => 'Valid Test', 'version' => '1.0.0']));

        try {
            $plugins = app(PluginService::class)->getAllPlugins();
            $slugs = array_column($plugins, 'slug');
            $this->assertContains('valid-manifest-test', $slugs);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
