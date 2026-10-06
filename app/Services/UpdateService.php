<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Update\BackupService;
use App\Services\Update\FileUpdateService;
use App\Services\Update\GitHubReleaseService;
use App\Support\ArtisanProcess;
use App\Support\SafeZipExtractor;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing application updates from GitHub releases.
 *
 * Handles the complete update process including backup creation,
 * downloading releases, file replacement, and database migrations.
 */
class UpdateService
{
    public const MAINTENANCE_RETRY_SECONDS = 60;

    /**
     * @var string The current installed version of the application
     */
    protected string $currentVersion;

    /**
     * @param  GitHubReleaseService  $githubService  Service for interacting with GitHub API
     * @param  BackupService  $backupService  Service for creating and managing backups
     * @param  FileUpdateService  $fileService  Service for downloading and extracting update files
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
     *
     * @throws Exception If backup creation fails
     */
    public function createBackup(): string
    {
        return $this->backupService->createBackup();
    }

    /**
     * How the database was captured by the last backup (mysqldump, pg_dump,
     * sqlite-snapshot, php-dump, or none).
     */
    public function lastDatabaseBackupMethod(): ?string
    {
        return $this->backupService->lastDatabaseBackupMethod();
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
     * @param  string|null  $downloadUrl  Optional direct download URL, otherwise fetches from GitHub
     * @param  callable|null  $progressCallback  Optional callback for progress updates
     * @return array{success: bool, message: string, backup_path?: string, new_version?: string, error?: string} Update result
     *
     * @throws Exception If critical update steps fail
     */
    public function update(?string $downloadUrl = null, ?callable $progressCallback = null): array
    {
        // Serialize updates: a second admin (or a scheduled trigger) must never run a
        // concurrent update — parallel file replacement + migrations would corrupt the
        // install — so fail fast if another update already holds the lock.
        $lock = Cache::lock('inventoros:update', 900);

        if (! $lock->get()) {
            return [
                'success' => false,
                'message' => 'An update is already in progress. Please wait for it to finish.',
                'error' => 'update_in_progress',
            ];
        }

        $maintenanceStarted = false;
        $wasInMaintenance = app()->isDownForMaintenance();
        $safeToResume = true;

        try {
            $this->log($progressCallback, 'Starting update process...');

            // Step 1: Get latest release info
            if (! $downloadUrl) {
                $this->log($progressCallback, 'Fetching latest release information...');
                $latest = $this->githubService->getLatestRelease();

                if (! $latest || ! $latest['version']) {
                    throw new Exception('Could not fetch latest release information');
                }

                $expectedVersion = $this->githubService->stripVersion((string) $latest['version']);

                if (! $latest['download_url']) {
                    throw new Exception(
                        "Release {$latest['version']} has no ".GitHubReleaseService::packageName($expectedVersion)
                        .' package attached, so it cannot be installed from here. Download it from GitHub and follow UPGRADE.md.'
                    );
                }

                $downloadUrl = $latest['download_url'];
            } else {
                // A direct URL: the package's own manifest names the version.
                $expectedVersion = null;
            }

            // Download and validate before taking the site offline.
            $this->log($progressCallback, 'Downloading update...');
            $zipPath = $this->fileService->downloadRelease($downloadUrl);

            // Step 3b: Verify the archive signature before it touches the app.
            $this->log($progressCallback, 'Verifying update signature...');
            $this->fileService->verifyArchiveSignature($zipPath, $downloadUrl);

            // Step 4: Extract to temp
            $this->log($progressCallback, 'Extracting files...');
            $extractPath = $this->fileService->extractZip($zipPath);

            // Step 4b: Check the package (manifest, version, PHP range, layout,
            // web root) while the site is still up; a bad package must never
            // take the site down.
            $this->log($progressCallback, 'Checking the release package...');
            try {
                $manifest = $this->fileService->validateRelease($extractPath, $expectedVersion);
            } catch (\Throwable $invalid) {
                $this->fileService->cleanup($zipPath, $extractPath);

                throw $invalid;
            }
            $newVersion = $manifest['version'];

            // Step 5: Put application in maintenance mode
            $this->log($progressCallback, 'Enabling maintenance mode...');
            if (Artisan::call('down', ['--retry' => self::MAINTENANCE_RETRY_SECONDS]) !== 0) {
                throw new Exception('Could not enable maintenance mode; the update was not applied.');
            }
            $maintenanceStarted = true;

            // The rollback snapshot must include every write accepted before
            // maintenance. Taking it before the download would lose intervening
            // orders/payments if a migration fails. Operators must still drain
            // in-flight requests/jobs; maintenance only prevents new normal jobs.
            $this->log($progressCallback, 'Creating backup...');
            $backupPath = $this->backupService->createBackup();
            $this->log($progressCallback, 'Database backed up using '.$this->backupService->lastDatabaseBackupMethod());

            // Steps 6-9 mutate the live installation: first the files, then the
            // database schema, then caches + the version marker. If ANY of them
            // fails the install is left in a broken half-state — most importantly
            // new application files running against the OLD schema when a migration
            // throws, or the old files against a partially-migrated schema. Restore
            // the pre-update backup (files AND the database dump) on any failure in
            // this block, not just a file-replacement failure.
            try {
                $safeToResume = false;
                // Step 6: Install the package files (app + web root)
                $this->log($progressCallback, 'Replacing application files...');
                $this->fileService->installRelease($extractPath);

                // Step 7: Drop caches compiled against the old code
                // (config, routes, package manifest) before anything boots
                // the new files.
                $this->log($progressCallback, 'Clearing caches...');
                $this->artisan('optimize:clear');

                // Step 8: Run migrations
                $this->log($progressCallback, 'Running database migrations...');
                $this->artisan('migrate', ['--force' => true]);

                // Step 9: Rebuild caches
                $this->log($progressCallback, 'Rebuilding caches...');
                $this->artisan('optimize');
                // Long-lived workers must boot the new code before their
                // next job. This signal does not drain already-running jobs.
                $this->artisan('queue:restart');

                // Step 10: Record the new version last, once everything
                // above succeeded.
                $this->fileService->writeVersion($newVersion);
                $safeToResume = true;
            } catch (\Throwable $applyError) {
                // The install is now half-updated (files and/or schema); restore
                // the maintenance-mode backup before surfacing the failure.
                Log::error('Update failed after mutating the installation; restoring from backup', [
                    'error' => $applyError->getMessage(),
                    'backup' => $backupPath,
                ]);
                $this->log($progressCallback, 'Update failed; restoring previous version...');
                $restore = $this->restoreFromBackup($backupPath);

                if (! ($restore['success'] ?? false)) {
                    throw new Exception(
                        'Update failed ('.$applyError->getMessage().') and restoring the backup also failed ('
                        .($restore['message'] ?? 'unknown error').'). The application remains in maintenance mode. Restore '.$backupPath.' by hand.',
                        0,
                        $applyError
                    );
                }
                $safeToResume = true;

                throw new Exception(
                    'Update failed and the previous version was restored: '
                    .$applyError->getMessage(),
                    0,
                    $applyError
                );
            }

            // Step 11: Cleanup temp files. Benign — these are temp artifacts only,
            // so a cleanup failure must not roll back an otherwise-successful update.
            $this->log($progressCallback, 'Cleaning up temporary files...');
            $this->fileService->cleanup($zipPath, $extractPath);

            $this->log($progressCallback, 'Update completed successfully!');

            return [
                'success' => true,
                'message' => 'Update completed successfully',
                'backup_path' => $backupPath,
                'new_version' => $newVersion,
            ];

        } catch (\Throwable $e) {
            Log::error('Update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            // The rollback path already words its message as "Update failed
            // ..."; don't say it twice.
            $message = str_starts_with($e->getMessage(), 'Update failed')
                ? $e->getMessage()
                : 'Update failed: '.$e->getMessage();

            $this->log($progressCallback, $message);

            return [
                'success' => false,
                'message' => $message,
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($maintenanceStarted && $safeToResume && ! $wasInMaintenance) {
                $this->log($progressCallback, 'Disabling maintenance mode...');
                try {
                    Artisan::call('up');
                } catch (\Throwable $upError) {
                    Log::error('Failed to bring the application up after the update', ['error' => $upError->getMessage()]);
                    $this->log($progressCallback, 'Could not disable maintenance mode: run php artisan up.');
                }
            }
            $lock->release();
        }
    }

    /**
     * Restore from backup.
     *
     * @param  string  $backupPath  Path to the backup file
     * @return array{success: bool, message: string, error?: string} Restore result
     */
    public function restoreFromBackup(string $backupPath): array
    {
        $maintenanceStarted = false;
        $wasInMaintenance = app()->isDownForMaintenance();
        $safeToResume = true;

        try {
            if (! File::exists($backupPath)) {
                throw new Exception('Backup file not found');
            }

            // Put application in maintenance mode
            if (Artisan::call('down') !== 0) {
                throw new Exception('Could not enable maintenance mode; the backup was not restored.');
            }
            $maintenanceStarted = true;

            $extractPath = $this->fileService->getTempPath().'/restore_'.time();
            File::makeDirectory($extractPath, 0755, true, true);

            $zip = new \ZipArchive;
            if ($zip->open($backupPath) !== true) {
                throw new Exception('Could not open backup file');
            }

            try {
                // Backups hold vendor/ and storage/, so they are allowed to
                // be much larger than a release package.
                SafeZipExtractor::validate($zip, $extractPath, [
                    'max_entries' => (int) config('update.restore_max_entry_count', 500000),
                    'max_bytes' => (int) config('update.restore_max_extracted_bytes', 4 * 1024 * 1024 * 1024),
                ]);
                $zip->extractTo($extractPath);
            } finally {
                $zip->close();
            }

            // Restore files
            $safeToResume = false;
            $this->fileService->replaceFiles($extractPath);

            // Restore the database the same way it was captured (the archive's
            // manifest records the method). A failure throws and fails the
            // whole restore loudly rather than reporting success over data
            // that was never rolled back.
            $databaseMethod = $this->backupService->restoreDatabase($extractPath);
            Log::info('Backup restore: database', ['method' => $databaseMethod ?? 'none (files-only backup)']);

            // Clear caches (in a fresh process: this one may have loaded
            // classes from the files that were just rolled back).
            $this->artisan('optimize:clear');
            $this->artisan('optimize');
            $this->artisan('queue:restart');
            $safeToResume = true;

            // Cleanup
            File::deleteDirectory($extractPath);

            return [
                'success' => true,
                'message' => 'Backup restored successfully',
            ];

        } catch (\Throwable $e) {
            Log::error('Restore failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Restore failed: '.$e->getMessage().($safeToResume ? '' : ' The application remains in maintenance mode; finish restoring it before running php artisan up.'),
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($maintenanceStarted && $safeToResume && ! $wasInMaintenance) {
                try {
                    Artisan::call('up');
                } catch (\Throwable $upException) {
                    Log::error('Failed to bring application up after restore', ['error' => $upException->getMessage()]);
                }
            }
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
     * Run an artisan command for the update.
     *
     * Once the new files are in place this PHP process still holds the old
     * classes, and config:cache boots a second application and re-points the
     * facades at it: running cache and migration commands in-process mixes old
     * and new code. They run as `php artisan ...` in a new process in the
     * application directory instead, which sees only the files on disk. When
     * processes cannot be started (proc_open disabled, no PHP CLI binary
     * found, or update.run_artisan_in_subprocess off) it falls back to an
     * in-process call.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws \RuntimeException When the command fails.
     */
    protected function artisan(string $command, array $parameters = []): void
    {
        $processes = app(ArtisanProcess::class);

        if ($processes->available()) {
            $processes->run($command, $parameters, $this->fileService->basePath());

            return;
        }

        $status = Artisan::call($command, $parameters);
        if ($status !== 0) {
            throw new \RuntimeException("php artisan {$command} failed (exit {$status}): ".trim(Artisan::output()));
        }
    }

    /**
     * Log message and call progress callback.
     *
     * @param  callable|null  $callback  Optional callback to receive progress messages
     * @param  string  $message  The message to log
     */
    protected function log(?callable $callback, string $message): void
    {
        Log::info($message, ['component' => 'update_service']);

        if ($callback) {
            $callback($message);
        }
    }
}
