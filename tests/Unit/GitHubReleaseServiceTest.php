<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\GitHubReleaseService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GitHubReleaseServiceTest extends TestCase
{
    private function fakeRelease(array $assets, string $tag = 'v2.0.0'): void
    {
        config(['app.github_repo' => 'Inventoros/Inventoros']);

        Http::fake([
            'api.github.com/repos/Inventoros/Inventoros/releases/latest' => Http::response([
                'tag_name' => $tag,
                'name' => "Release {$tag}",
                'body' => 'notes',
                'published_at' => '2026-10-01T00:00:00Z',
                'html_url' => "https://github.com/Inventoros/Inventoros/releases/tag/{$tag}",
                'zipball_url' => "https://api.github.com/repos/Inventoros/Inventoros/zipball/{$tag}",
                'assets' => array_map(fn (string $name) => [
                    'name' => $name,
                    'browser_download_url' => "https://github.com/Inventoros/Inventoros/releases/download/{$tag}/{$name}",
                ], $assets),
            ]),
        ]);
    }

    public function test_selects_the_cpanel_package_by_exact_name_even_when_another_zip_sorts_first(): void
    {
        $this->fakeRelease([
            'hello-world-plugin.zip',
            'hello-world-plugin.zip.sig',
            'inventoros-cpanel-2.0.0.zip',
            'inventoros-cpanel-2.0.0.zip.sig',
        ]);

        $release = (new GitHubReleaseService)->getLatestRelease();

        $this->assertSame(
            'https://github.com/Inventoros/Inventoros/releases/download/v2.0.0/inventoros-cpanel-2.0.0.zip',
            $release['download_url']
        );
        $this->assertSame('inventoros-cpanel-2.0.0.zip', $release['asset_name']);
    }

    public function test_never_falls_back_to_the_source_zipball(): void
    {
        $this->fakeRelease(['hello-world-plugin.zip', 'inventoros-cpanel-1.9.0.zip']);

        $release = (new GitHubReleaseService)->getLatestRelease();

        $this->assertSame('v2.0.0', $release['version']);
        $this->assertNull($release['download_url']);
    }

    public function test_the_package_name_is_the_version_without_the_v_prefix(): void
    {
        $this->assertSame('inventoros-cpanel-2.0.0.zip', GitHubReleaseService::packageName('v2.0.0'));
        $this->assertSame('inventoros-cpanel-2.0.0.zip', GitHubReleaseService::packageName('2.0.0'));
    }
}
