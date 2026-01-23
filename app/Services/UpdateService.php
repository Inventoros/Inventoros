<?php

namespace App\Services;

use App\Services\Update\BackupService;
use App\Services\Update\FileUpdateService;
use App\Services\Update\GitHubReleaseService;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class UpdateService
{
    protected string $currentVersion;

    public function __construct(
        protected GitHubReleaseService $githubService,
        protected BackupService $backupService,
        protected FileUpdateService $fileService,
    ) {
        $this->currentVersion = $this->readVersionFile();
    }

    /**
     * Get current installed version
     */
    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    /**
     * Get the latest release information from GitHub
     */
    public function getLatestRelease(): ?array
    {
        return $this->githubService->getLatestRelease();
    }

    /**
     * Check if an update is available
     */
    public function isUpdateAvailable(): bool
    {
        return $this->githubService->isUpdateAvailable($this->currentVersion);
    }

    /**
     * Create a backup of the current installation
     */
    public function createBackup(): string
    {
        return $this->backupService->createBackup();
    }

    /**
     * List available backups
     */
    public function listBackups(): array
    {
        return $this->backupService->listBackups();
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
     * Read version from VERSION file
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
     * Write version to VERSION file
     */
    protected function writeVersionFile(string $version): void
    {
        $versionFile = base_path('VERSION');
        File::put($versionFile, $version);
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
}
