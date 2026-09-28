<?php

declare(strict_types=1);

namespace App\Services\Update;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for interacting with GitHub releases API.
 *
 * Fetches release information and determines update availability
 * by comparing versions with the GitHub repository.
 */
class GitHubReleaseService
{
    /**
     * @var string The base URL for GitHub API requests
     */
    protected string $githubApiUrl;

    /**
     * @var string The GitHub repository in owner/repo format
     */
    protected string $githubRepo;

    /**
     * Initialize the service with repository configuration.
     */
    public function __construct()
    {
        $this->githubRepo = config('app.github_repo', 'owner/repository');
        $this->githubApiUrl = "https://api.github.com/repos/{$this->githubRepo}";
    }

    /**
     * Get the latest release information from GitHub.
     *
     * @return array|null Release data containing version, name, body, published_at, download_url, and html_url, or null on failure
     */
    public function getLatestRelease(): ?array
    {
        try {
            $response = Http::timeout(config('limits.timeouts.github_api'))
                ->get("{$this->githubApiUrl}/releases/latest");

            if ($response->successful()) {
                $release = $response->json();

                $asset = $this->findPackageAsset($release);

                return [
                    'version' => $release['tag_name'] ?? null,
                    'name' => $release['name'] ?? null,
                    'body' => $release['body'] ?? null,
                    'published_at' => $release['published_at'] ?? null,
                    'download_url' => $asset['url'] ?? null,
                    'asset_name' => $asset['name'] ?? null,
                    'html_url' => $release['html_url'] ?? null,
                ];
            }

            return null;
        } catch (Exception $e) {
            Log::error('Failed to fetch latest release', [
                'repository' => $this->githubRepo,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if an update is available compared to current version.
     *
     * @param string $currentVersion The currently installed version
     * @return bool True if a newer version is available on GitHub
     */
    public function isUpdateAvailable(string $currentVersion): bool
    {
        $latest = $this->getLatestRelease();

        if (!$latest || !$latest['version']) {
            return false;
        }

        return version_compare(
            $this->stripVersion($latest['version']),
            $this->stripVersion($currentVersion),
            '>'
        );
    }

    /**
     * The file name of the cPanel package attached to a release.
     *
     * Only this exact asset is ever installed: every release also carries
     * other ZIPs (the example plugin), and the source zipball has neither
     * vendor/ nor built assets, so "the first .zip" or a zipball fallback
     * would install the wrong thing.
     */
    public static function packageName(string $version): string
    {
        return 'inventoros-cpanel-'.ltrim($version, 'v').'.zip';
    }

    /**
     * Find the cPanel package asset for the release's own version.
     *
     * @param  array<string, mixed>  $release  The release data from the GitHub API
     * @return array{name: string, url: string}|null Null when the release has no such asset
     */
    protected function findPackageAsset(array $release): ?array
    {
        $tag = (string) ($release['tag_name'] ?? '');
        if ($tag === '' || ! isset($release['assets']) || ! is_array($release['assets'])) {
            return null;
        }

        $expected = self::packageName($tag);

        foreach ($release['assets'] as $asset) {
            if (($asset['name'] ?? null) === $expected && ! empty($asset['browser_download_url'])) {
                return ['name' => $expected, 'url' => (string) $asset['browser_download_url']];
            }
        }

        return null;
    }

    /**
     * Strip 'v' prefix from version string.
     *
     * @param string $version The version string (e.g., 'v1.0.0')
     * @return string The version without 'v' prefix (e.g., '1.0.0')
     */
    public function stripVersion(string $version): string
    {
        return ltrim($version, 'v');
    }
}
