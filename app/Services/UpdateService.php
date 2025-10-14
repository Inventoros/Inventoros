<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class UpdateService
{
    protected string $githubApiUrl;
    protected string $githubRepo;
    protected string $currentVersion;
    protected string $backupPath;
    protected string $tempPath;

    public function __construct()
    {
        $this->githubRepo = config('app.github_repo', 'owner/repository');
        $this->githubApiUrl = "https://api.github.com/repos/{$this->githubRepo}";
        $this->currentVersion = config('app.version', '1.0.0');
        $this->backupPath = storage_path('app/backups');
        $this->tempPath = storage_path('app/temp');
    }

    /**
     * Get the latest release information from GitHub
     */
    public function getLatestRelease(): ?array
    {
        try {
            $response = Http::timeout(30)
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
     * Check if an update is available
     */
    public function isUpdateAvailable(): bool
    {
        $latest = $this->getLatestRelease();

        if (!$latest || !$latest['version']) {
            return false;
        }

        return version_compare($this->stripVersion($latest['version']), $this->stripVersion($this->currentVersion), '>');
    }

    /**
     * Get current installed version
     */
    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    /**
     * Perform the update process
     */
    public function update(?string $downloadUrl = null, callable $progressCallback = null): array
    {
        try {
            $this->log($progressCallback, 'Starting update process...');

            // Step 1: Get latest release info
            if (!$downloadUrl) {
                $this->log($progressCallback, 'Fetching latest release information...');
                $latest = $this->getLatestRelease();

                if (!$latest || !$latest['download_url']) {
                    throw new Exception('Could not fetch latest release information');
                }

                $downloadUrl = $latest['download_url'];
                $newVersion = $latest['version'];
            } else {
                $newVersion = 'unknown';
            }

            // Step 2: Create backup
            $this->log($progressCallback, 'Creating backup...');
            $backupPath = $this->createBackup();

            // Step 3: Download release
            $this->log($progressCallback, 'Downloading update...');
            $zipPath = $this->downloadRelease($downloadUrl);

            // Step 4: Extract to temp
            $this->log($progressCallback, 'Extracting files...');
            $extractPath = $this->extractZip($zipPath);

            // Step 5: Put application in maintenance mode
            $this->log($progressCallback, 'Enabling maintenance mode...');
            Artisan::call('down', ['--retry' => 60]);

            try {
                // Step 6: Replace files
                $this->log($progressCallback, 'Replacing application files...');
                $this->replaceFiles($extractPath);

                // Step 7: Run migrations
                $this->log($progressCallback, 'Running database migrations...');
                Artisan::call('migrate', ['--force' => true]);

                // Step 8: Clear and rebuild caches
                $this->log($progressCallback, 'Clearing caches...');
                Artisan::call('optimize:clear');

                $this->log($progressCallback, 'Rebuilding caches...');
                Artisan::call('optimize');

                // Step 9: Update version in config
                if ($newVersion !== 'unknown') {
                    $this->updateVersionInEnv($newVersion);
                }

                // Step 10: Cleanup temp files
                $this->log($progressCallback, 'Cleaning up temporary files...');
                $this->cleanup($zipPath, $extractPath);

            } finally {
                // Always bring the application back up
                $this->log($progressCallback, 'Disabling maintenance mode...');
                Artisan::call('up');
            }

            $this->log($progressCallback, 'Update completed successfully!');

            return [
                'success' => true,
                'message' => 'Update completed successfully',
                'backup_path' => $backupPath,
                'new_version' => $newVersion,
            ];

        } catch (Exception $e) {
            Log::error('Update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            $this->log($progressCallback, 'Update failed: ' . $e->getMessage());

            // Attempt to bring the application back up if it's down
            try {
                Artisan::call('up');
            } catch (Exception $upException) {
                Log::error('Failed to bring application up after error', ['error' => $upException->getMessage()]);
            }

            return [
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a backup of the current installation
     */
    public function createBackup(): string
    {
        if (!File::exists($this->backupPath)) {
            File::makeDirectory($this->backupPath, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');
        $backupFile = "{$this->backupPath}/backup_{$timestamp}.zip";

        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create backup file');
        }

        $basePath = base_path();

        // Files and directories to backup
        $itemsToBackup = [
            '.env',
            'app',
            'bootstrap',
            'config',
            'database',
            'public',
            'resources',
            'routes',
            'storage',
        ];

        foreach ($itemsToBackup as $item) {
            $path = "{$basePath}/{$item}";

            if (File::isFile($path)) {
                $zip->addFile($path, $item);
            } elseif (File::isDirectory($path)) {
                $this->addDirectoryToZip($zip, $path, $item);
            }
        }

        // Backup database
        $this->backupDatabase("{$this->backupPath}/database_{$timestamp}.sql");

        $zip->close();

        return $backupFile;
    }

    /**
     * Add directory recursively to zip
     */
    protected function addDirectoryToZip(ZipArchive $zip, string $path, string $zipPath): void
    {
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = $zipPath . '/' . $file->getRelativePathname();
            $zip->addFile($filePath, $relativePath);
        }
    }

    /**
     * Backup database to SQL file
     */
    protected function backupDatabase(string $outputPath): void
    {
        try {
            $dbConnection = config('database.default');
            $dbConfig = config("database.connections.{$dbConnection}");

            if ($dbConfig['driver'] === 'mysql') {
                $command = sprintf(
                    'mysqldump --user=%s --password=%s --host=%s %s > %s',
                    escapeshellarg($dbConfig['username']),
                    escapeshellarg($dbConfig['password']),
                    escapeshellarg($dbConfig['host']),
                    escapeshellarg($dbConfig['database']),
                    escapeshellarg($outputPath)
                );

                exec($command, $output, $returnCode);

                if ($returnCode !== 0) {
                    Log::warning('Database backup failed', ['return_code' => $returnCode]);
                }
            } else {
                Log::info('Database backup skipped for non-MySQL database');
            }
        } catch (Exception $e) {
            Log::warning('Database backup failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Download release from GitHub
     */
    protected function downloadRelease(string $url): string
    {
        if (!File::exists($this->tempPath)) {
            File::makeDirectory($this->tempPath, 0755, true);
        }

        $zipPath = "{$this->tempPath}/update_" . time() . ".zip";

        $response = Http::timeout(300)->get($url);

        if (!$response->successful()) {
            throw new Exception('Failed to download release');
        }

        File::put($zipPath, $response->body());

        return $zipPath;
    }

    /**
     * Extract ZIP file
     */
    protected function extractZip(string $zipPath): string
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
    protected function replaceFiles(string $sourcePath): void
    {
        $basePath = base_path();

        // Directories to update
        $directoriesToUpdate = [
            'app',
            'bootstrap',
            'config',
            'database',
            'public',
            'resources',
            'routes',
        ];

        // Files to preserve
        $filesToPreserve = [
            '.env',
            'storage',
            'public/storage',
        ];

        foreach ($directoriesToUpdate as $dir) {
            $sourceDir = "{$sourcePath}/{$dir}";
            $destDir = "{$basePath}/{$dir}";

            if (File::exists($sourceDir)) {
                // Remove old directory
                if (File::exists($destDir)) {
                    File::deleteDirectory($destDir);
                }

                // Copy new directory
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
     * Update version in .env file
     */
    protected function updateVersionInEnv(string $newVersion): void
    {
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            return;
        }

        $envContent = File::get($envPath);

        if (preg_match('/^APP_VERSION=.*/m', $envContent)) {
            $envContent = preg_replace('/^APP_VERSION=.*/m', "APP_VERSION={$newVersion}", $envContent);
        } else {
            $envContent .= "\nAPP_VERSION={$newVersion}\n";
        }

        File::put($envPath, $envContent);
    }

    /**
     * Cleanup temporary files
     */
    protected function cleanup(string $zipPath, string $extractPath): void
    {
        if (File::exists($zipPath)) {
            File::delete($zipPath);
        }

        if (File::exists($extractPath)) {
            File::deleteDirectory($extractPath);
        }
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
    protected function stripVersion(string $version): string
    {
        return ltrim($version, 'v');
    }

    /**
     * Log message and call progress callback
     */
    protected function log(?callable $callback, string $message): void
    {
        Log::info($message);

        if ($callback) {
            $callback($message);
        }
    }

    /**
     * Restore from backup
     */
    public function restoreFromBackup(string $backupPath): array
    {
        try {
            if (!File::exists($backupPath)) {
                throw new Exception('Backup file not found');
            }

            // Put application in maintenance mode
            Artisan::call('down');

            $extractPath = "{$this->tempPath}/restore_" . time();

            $zip = new ZipArchive();
            if ($zip->open($backupPath) !== true) {
                throw new Exception('Could not open backup file');
            }

            $zip->extractTo($extractPath);
            $zip->close();

            // Restore files
            $this->replaceFiles($extractPath);

            // Clear caches
            Artisan::call('optimize:clear');
            Artisan::call('optimize');

            // Cleanup
            File::deleteDirectory($extractPath);

            // Bring application back up
            Artisan::call('up');

            return [
                'success' => true,
                'message' => 'Backup restored successfully',
            ];

        } catch (Exception $e) {
            Log::error('Restore failed', ['error' => $e->getMessage()]);

            try {
                Artisan::call('up');
            } catch (Exception $upException) {
                Log::error('Failed to bring application up after restore error');
            }

            return [
                'success' => false,
                'message' => 'Restore failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List available backups
     */
    public function listBackups(): array
    {
        if (!File::exists($this->backupPath)) {
            return [];
        }

        $backups = [];
        $files = File::files($this->backupPath);

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.zip')) {
                $backups[] = [
                    'filename' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'created_at' => $file->getMTime(),
                ];
            }
        }

        // Sort by creation time, newest first
        usort($backups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }
}
