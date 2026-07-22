<?php

declare(strict_types=1);

namespace App\Services\Update;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Service for managing application backups.
 *
 * Creates, lists, and restores backups of the application files
 * and database for safe updates.
 */
class BackupService
{
    /**
     * @var string Path to the backup storage directory
     */
    protected string $backupPath;

    /**
     * Initialize the service and set up backup path.
     *
     * @param  string|null  $backupPath  Override the backup directory (mainly
     *                                   for testing); defaults to the app's
     *                                   storage/app/backups.
     */
    public function __construct(?string $backupPath = null)
    {
        $this->backupPath = $backupPath ?? storage_path('app/backups');
    }

    /**
     * The application base path that backups are taken relative to. Overridable
     * so the archive logic can be exercised against a fixture tree in tests.
     */
    protected function basePath(): string
    {
        return base_path();
    }

    /**
     * The top-level files and directories included in a backup.
     *
     * @return array<int, string>
     */
    protected function itemsToBackup(): array
    {
        return [
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
    }

    /**
     * Create a backup of the current installation.
     *
     * Backs up application directories, configuration, and database.
     *
     * @return string Path to the created backup file
     *
     * @throws Exception If backup creation fails
     */
    public function createBackup(): string
    {
        $this->ensureBackupDirectoryExists();

        $timestamp = now()->format('Y-m-d_His');
        $backupFile = "{$this->backupPath}/backup_{$timestamp}.zip";

        $zip = new ZipArchive;
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create backup file');
        }

        $basePath = $this->basePath();

        // Never recurse into the backup directory itself: it lives under
        // storage/, so backing "storage" up unguarded would embed every prior
        // backup (and the in-progress archive) into each new one, ballooning
        // disk use until backups fail.
        $excludePaths = [realpath($this->backupPath) ?: $this->backupPath];

        foreach ($this->itemsToBackup() as $item) {
            $path = "{$basePath}/{$item}";

            if (File::isFile($path)) {
                $zip->addFile($path, $item);
            } elseif (File::isDirectory($path)) {
                $this->addDirectoryToZip($zip, $path, $item, $excludePaths);
            }
        }

        // Dump the database and fold it INTO the archive as database.sql so a
        // restore can import it. Previously the dump was written next to the
        // zip and never added, so MySQL backups shipped with no data at all.
        $dumpPath = "{$this->backupPath}/database_{$timestamp}.sql";
        if ($this->backupDatabase($dumpPath)) {
            $zip->addFile($dumpPath, 'database.sql');
        }

        $zip->close();

        // The dump is only read by addFile() at close(); remove the loose copy
        // now that it is safely inside the archive.
        if (File::exists($dumpPath)) {
            File::delete($dumpPath);
        }

        return $backupFile;
    }

    /**
     * List available backups.
     *
     * @return array<int, array{filename: string, path: string, size: int, created_at: int}> List of backup files sorted by creation time (newest first)
     */
    public function listBackups(): array
    {
        if (! File::exists($this->backupPath)) {
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
        usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /**
     * Delete a backup file.
     *
     * @param  string  $filename  The backup filename to delete
     * @return bool True if deletion was successful, false if file not found
     */
    public function deleteBackup(string $filename): bool
    {
        $path = "{$this->backupPath}/{$filename}";

        if (! File::exists($path)) {
            return false;
        }

        return File::delete($path);
    }

    /**
     * Get backup path.
     *
     * @return string The backup storage directory path
     */
    public function getBackupPath(): string
    {
        return $this->backupPath;
    }

    /**
     * Ensure backup directory exists.
     */
    protected function ensureBackupDirectoryExists(): void
    {
        if (! File::exists($this->backupPath)) {
            File::makeDirectory($this->backupPath, config('limits.permissions.directory'), true);
        }
    }

    /**
     * Add directory recursively to zip.
     *
     * @param  ZipArchive  $zip  The zip archive to add files to
     * @param  string  $path  The filesystem path to the directory
     * @param  string  $zipPath  The path within the zip archive
     */
    protected function addDirectoryToZip(ZipArchive $zip, string $path, string $zipPath, array $excludePaths = []): void
    {
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $filePath = $file->getRealPath();

            if ($this->isExcluded($filePath, $excludePaths)) {
                continue;
            }

            $relativePath = $zipPath.'/'.$file->getRelativePathname();
            $zip->addFile($filePath, $relativePath);
        }
    }

    /**
     * Whether a file lives under any excluded directory.
     *
     * @param  array<int, string>  $excludePaths
     */
    protected function isExcluded(string $filePath, array $excludePaths): bool
    {
        $normalized = str_replace('\\', '/', $filePath);

        foreach ($excludePaths as $exclude) {
            $exclude = rtrim(str_replace('\\', '/', $exclude), '/');

            if ($exclude !== '' && str_starts_with($normalized, $exclude.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Backup the database to a SQL file.
     *
     * Currently dumps MySQL databases via mysqldump. Other drivers (e.g.
     * SQLite) store their data as a file that is already captured by the
     * directory backup, so no separate dump is produced.
     *
     * @param  string  $outputPath  Path to write the SQL backup file
     * @return bool Whether a dump file was produced at $outputPath
     */
    protected function backupDatabase(string $outputPath): bool
    {
        try {
            $dbConnection = config('database.default');
            $dbConfig = config("database.connections.{$dbConnection}");

            if (($dbConfig['driver'] ?? null) !== 'mysql') {
                Log::info('Database dump skipped for non-MySQL database', [
                    'driver' => $dbConfig['driver'] ?? 'unknown',
                ]);

                return false;
            }

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

                return false;
            }

            return File::exists($outputPath);
        } catch (Exception $e) {
            Log::warning('Database backup failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Import a database dump produced by a backup.
     *
     * Only meaningful for MySQL — SQLite/other file-based databases are
     * restored by replacing their data file, so this is a graceful no-op there.
     *
     * @param  string  $sqlPath  Path to the extracted database.sql
     * @return bool Whether the dump was imported
     */
    public function importDatabaseDump(string $sqlPath): bool
    {
        try {
            if (! File::exists($sqlPath)) {
                return false;
            }

            $dbConnection = config('database.default');
            $dbConfig = config("database.connections.{$dbConnection}");

            if (($dbConfig['driver'] ?? null) !== 'mysql') {
                Log::info('Database import skipped for non-MySQL database', [
                    'driver' => $dbConfig['driver'] ?? 'unknown',
                ]);

                return false;
            }

            $command = sprintf(
                'mysql --user=%s --password=%s --host=%s %s < %s',
                escapeshellarg($dbConfig['username']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($sqlPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                Log::error('Database import failed', ['return_code' => $returnCode]);

                return false;
            }

            return true;
        } catch (Exception $e) {
            Log::error('Database import failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
