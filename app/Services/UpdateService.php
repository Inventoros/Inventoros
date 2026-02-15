<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Update\BackupService;
use App\Services\Update\FileUpdateService;
use App\Services\Update\GitHubReleaseService;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing application updates from GitHub releases.
 *
 * Handles the complete update process including backup creation,
 * downloading releases, file replacement, and database migrations.
 */
final class UpdateService
{
    public const MAINTENANCE_RETRY_SECONDS = 60;

    /**
     * @var string The current installed version of the application
     */
    protected string $currentVersion;

    /**
     * @param GitHubReleaseService $githubService Service for interacting with GitHub API
     * @param BackupService $backupService Service for creating and managing backups
     * @param FileUpdateService $fileService Service for downloading and extracting update files
     */
    public function __construct(
        protected GitHubReleaseService $githubService,
        protected BackupService $backupService,
        protected FileUpdateService $fileService,
    ) {
        $this->currentVersion = $this->readVersionFile();
    }

    /**
     * Get current installed version.
     *
     * @return string The current version string
     */
    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    /**
     * Get the latest release information from GitHub.
     *
     * @return array|null Release information containing version, name, body, download_url, etc., or null if unavailable
     */
    public function getLatestRelease(): ?array
    {
        return $this->githubService->getLatestRelease();
    }

    /**
     * Check if an update is available.
     *
     * @return bool True if a newer version is available
     */
    public function isUpdateAvailable(): bool
    {
        return $this->githubService->isUpdateAvailable($this->currentVersion);
    }

    /**
     * Create a backup of the current installation.
     *
     * @return string Path to the created backup file
     * @throws Exception If backup creation fails
     */
    public function createBackup(): string
    {
        return $this->backupService->createBackup();
    }

    /**
     * List available backups.
     *
     * @return array<int, array{filename: string, path: string, size: int, created_at: int}> List of backup files sorted by creation time
     */
    public function listBackups(): array
    {
        return $this->backupService->listBackups();
    }

    /**
     * Perform the update process.
     *
     * @param string|null $downloadUrl Optional direct download URL, otherwise fetches from GitHub
     * @param callable|null $progressCallback Optional callback for progress updates
     * @return array{success: bool, message: string, backup_path?: string, new_version?: string, error?: string} Update result
     * @throws Exception If critical update steps fail
     */
    public function update(?string $downloadUrl = null, callable $progressCallback = null): array
    {
        try {
            $this->log($progressCallback, 'Starting update process...');

            // Step 1: Get latest release info
            if (!$downloadUrl) {
                $this->log($progressCallback, 'Fetching latest release information...');
                $latest = $this->githubService->getLatestRelease();

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
            $backupPath = $this->backupService->createBackup();

            // Step 3: Download release
            $this->log($progressCallback, 'Downloading update...');
            $zipPath = $this->fileService->downloadRelease($downloadUrl);

            // Step 4: Extract to temp
            $this->log($progressCallback, 'Extracting files...');
            $extractPath = $this->fileService->extractZip($zipPath);

            // Step 5: Put application in maintenance mode
            $this->log($progressCallback, 'Enabling maintenance mode...');
            Artisan::call('down', ['--retry' => 60]);

            try {
                // Step 6: Replace files
                $this->log($progressCallback, 'Replacing application files...');
                $this->fileService->replaceFiles($extractPath);

                // Step 7: Run migrations
                $this->log($progressCallback, 'Running database migrations...');
                Artisan::call('migrate', ['--force' => true]);

                // Step 8: Clear and rebuild caches
                $this->log($progressCallback, 'Clearing caches...');
                Artisan::call('optimize:clear');

                $this->log($progressCallback, 'Rebuilding caches...');
                Artisan::call('optimize');

                // Step 9: Update version file
                if ($newVersion !== 'unknown') {
                    $this->writeVersionFile($this->githubService->stripVersion($newVersion));
                }

                // Step 10: Cleanup temp files
                $this->log($progressCallback, 'Cleaning up temporary files...');
                $this->fileService->cleanup($zipPath, $extractPath);

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
     * Restore from backup.
     *
     * @param string $backupPath Path to the backup file
     * @return array{success: bool, message: string, error?: string} Restore result
     */
    public function restoreFromBackup(string $backupPath): array
    {
        try {
            if (!File::exists($backupPath)) {
                throw new Exception('Backup file not found');
            }

            // Put application in maintenance mode
            Artisan::call('down');

            $extractPath = $this->fileService->getTempPath() . '/restore_' . time();

            $zip = new \ZipArchive();
            if ($zip->open($backupPath) !== true) {
                throw new Exception('Could not open backup file');
            }

            $zip->extractTo($extractPath);
            $zip->close();

            // Restore files
            $this->fileService->replaceFiles($extractPath);

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
                Log::error('Failed to bring application up after restore error', [
                    'error' => $upException->getMessage(),
                ]);
            }

            return [
                'success' => false,
                'message' => 'Restore failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Read version from VERSION file.
     *
     * @return string The version string from file, or '1.0.0' as default
     */
    protected function readVersionFile(): string
    {
        $versionFile = base_path('VERSION');

        if (File::exists($versionFile)) {
            return trim(File::get($versionFile));
        }

        return '1.0.0';
    }

    /**
     * Write version to VERSION file.
     *
     * @param string $version The version string to write
     * @return void
     */
    protected function writeVersionFile(string $version): void
    {
        $versionFile = base_path('VERSION');
        File::put($versionFile, $version);
    }

    /**
     * Log message and call progress callback.
     *
     * @param callable|null $callback Optional callback to receive progress messages
     * @param string $message The message to log
     * @return void
     */
    protected function log(?callable $callback, string $message): void
    {
        Log::info($message, ['component' => 'update_service']);

        if ($callback) {
            $callback($message);
        }
    }
}
