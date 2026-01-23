<?php

namespace App\Services\Update;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubReleaseService
{
    protected string $githubApiUrl;
    protected string $githubRepo;

    public function __construct()
    {
        $this->githubRepo = config('app.github_repo', 'owner/repository');
        $this->githubApiUrl = "https://api.github.com/repos/{$this->githubRepo}";
    }

    /**
     * Get the latest release information from GitHub
     */
    public function getLatestRelease(): ?array
    {
        try {
            $response = Http::timeout(config('limits.timeouts.github_api'))
                ->get("{$this->githubApiUrl}/releases/latest");

            if ($response->successful()) {
                $release = $response->json();

                return [
                    'version' => $release['tag_name'] ?? null,
                    'name' => $release['name'] ?? null,
                    'body' => $release['body'] ?? null,
                    'published_at' => $release['published_at'] ?? null,
                    'download_url' => $this->getZipDownloadUrl($release),
                    'html_url' => $release['html_url'] ?? null,
                ];
            }

            return null;
        } catch (Exception $e) {
            Log::error('Failed to fetch latest release', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Check if an update is available compared to current version
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
     * Get ZIP download URL from release data
     */
    protected function getZipDownloadUrl(array $release): ?string
    {
        // Try to find a release asset ZIP file
        if (isset($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['name']) && str_ends_with($asset['name'], '.zip')) {
                    return $asset['browser_download_url'] ?? null;
                }
            }
        }

        // Fallback to zipball URL
        return $release['zipball_url'] ?? null;
    }

    /**
     * Strip 'v' prefix from version string
     */
    public function stripVersion(string $version): string
    {
        return ltrim($version, 'v');
    }
}
