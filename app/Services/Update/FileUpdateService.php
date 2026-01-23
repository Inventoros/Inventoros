<?php

namespace App\Services\Update;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class FileUpdateService
{
    protected string $tempPath;

    public function __construct()
    {
        $this->tempPath = storage_path('app/temp');
    }

    /**
     * Download release from URL
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
     * Extract ZIP file
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
     * Replace application files with new ones
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
     * Cleanup temporary files
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
     * Get temp path
     */
    public function getTempPath(): string
    {
        return $this->tempPath;
    }

    /**
     * Ensure temp directory exists
     */
    protected function ensureTempDirectoryExists(): void
    {
        if (!File::exists($this->tempPath)) {
            File::makeDirectory($this->tempPath, config('limits.permissions.directory'), true);
        }
    }
}
