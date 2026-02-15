<?php

declare(strict_types=1);

namespace App\Services\Update;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Service for handling file operations during updates.
 *
 * Downloads release archives, extracts them, and replaces
 * application files with new versions.
 */
final class FileUpdateService
{
    /**
     * @var string Path to temporary storage directory for update files
     */
    protected string $tempPath;

    /**
     * Initialize the service and set up temp path.
     */
    public function __construct()
    {
        $this->tempPath = storage_path('app/temp');
    }

    /**
     * Download release from URL.
     *
     * @param string $url The URL to download the release from
     * @return string Path to the downloaded ZIP file
     * @throws Exception If download fails
     */
    public function downloadRelease(string $url): string
    {
        $this->ensureTempDirectoryExists();

        $zipPath = "{$this->tempPath}/update_" . time() . ".zip";

        $response = Http::timeout(config('limits.timeouts.file_download'))->get($url);

        if (!$response->successful()) {
            throw new Exception('Failed to download release');
        }

        File::put($zipPath, $response->body());

        return $zipPath;
    }

    /**
     * Extract ZIP file to temporary directory.
     *
     * Handles GitHub's root folder structure by flattening if necessary.
     *
     * @param string $zipPath Path to the ZIP file
     * @return string Path to the extracted directory
     * @throws Exception If extraction fails
     */
    public function extractZip(string $zipPath): string
    {
        $extractPath = "{$this->tempPath}/extracted_" . time();

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new Exception('Could not open downloaded ZIP file');
        }

        // GitHub releases often have a root folder, we need to handle that
        $zip->extractTo($extractPath);
        $zip->close();

        // Check if there's a single root directory
        $contents = File::directories($extractPath);
        if (count($contents) === 1 && count(File::files($extractPath)) === 0) {
            // Move contents up one level
            $rootDir = $contents[0];
            $tempExtractPath = $extractPath . '_final';
            File::moveDirectory($rootDir, $tempExtractPath);
            File::deleteDirectory($extractPath);
            File::moveDirectory($tempExtractPath, $extractPath);
        }

        return $extractPath;
    }

    /**
     * Replace application files with new ones.
     *
     * Copies directories and key files from source to base path.
     *
     * @param string $sourcePath Path to the extracted update files
     * @return void
     */
    public function replaceFiles(string $sourcePath): void
    {
        $basePath = base_path();

        $directoriesToUpdate = [
            'app',
            'bootstrap',
            'config',
            'database',
            'public',
            'resources',
            'routes',
        ];

        foreach ($directoriesToUpdate as $dir) {
            $sourceDir = "{$sourcePath}/{$dir}";
            $destDir = "{$basePath}/{$dir}";

            if (File::exists($sourceDir)) {
                if (File::exists($destDir)) {
                    File::deleteDirectory($destDir);
                }

                File::copyDirectory($sourceDir, $destDir);
            }
        }

        // Update composer files
        $composerFiles = ['composer.json', 'composer.lock'];
        foreach ($composerFiles as $file) {
            if (File::exists("{$sourcePath}/{$file}")) {
                File::copy("{$sourcePath}/{$file}", "{$basePath}/{$file}");
            }
        }

        // Update package files
        $packageFiles = ['package.json', 'package-lock.json', 'vite.config.js', 'tailwind.config.js'];
        foreach ($packageFiles as $file) {
            if (File::exists("{$sourcePath}/{$file}")) {
                File::copy("{$sourcePath}/{$file}", "{$basePath}/{$file}");
            }
        }
    }

    /**
     * Cleanup temporary files after update.
     *
     * @param string $zipPath Path to the downloaded ZIP file
     * @param string $extractPath Path to the extracted directory
     * @return void
     */
    public function cleanup(string $zipPath, string $extractPath): void
    {
        if (File::exists($zipPath)) {
            File::delete($zipPath);
        }

        if (File::exists($extractPath)) {
            File::deleteDirectory($extractPath);
        }
    }

    /**
     * Get temp path.
     *
     * @return string The temporary storage path
     */
    public function getTempPath(): string
    {
        return $this->tempPath;
    }

    /**
     * Ensure temp directory exists.
     *
     * @return void
     */
    protected function ensureTempDirectoryExists(): void
    {
        if (!File::exists($this->tempPath)) {
            File::makeDirectory($this->tempPath, config('limits.permissions.directory'), true);
        }
    }
}
