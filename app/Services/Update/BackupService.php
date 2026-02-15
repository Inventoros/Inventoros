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
final class BackupService
{
    /**
     * @var string Path to the backup storage directory
     */
    protected string $backupPath;

    /**
     * Initialize the service and set up backup path.
     */
    public function __construct()
    {
        $this->backupPath = storage_path('app/backups');
    }

    /**
     * Create a backup of the current installation.
     *
     * Backs up application directories, configuration, and database.
     *
     * @return string Path to the created backup file
     * @throws Exception If backup creation fails
     */
    public function createBackup(): string
    {
        $this->ensureBackupDirectoryExists();

        $timestamp = now()->format('Y-m-d_His');
        $backupFile = "{$this->backupPath}/backup_{$timestamp}.zip";

        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create backup file');
        }

        $basePath = base_path();

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
     * List available backups.
     *
     * @return array<int, array{filename: string, path: string, size: int, created_at: int}> List of backup files sorted by creation time (newest first)
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

    /**
     * Delete a backup file.
     *
     * @param string $filename The backup filename to delete
     * @return bool True if deletion was successful, false if file not found
     */
    public function deleteBackup(string $filename): bool
    {
        $path = "{$this->backupPath}/{$filename}";

        if (!File::exists($path)) {
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
     *
     * @return void
     */
    protected function ensureBackupDirectoryExists(): void
    {
        if (!File::exists($this->backupPath)) {
            File::makeDirectory($this->backupPath, config('limits.permissions.directory'), true);
        }
    }

    /**
     * Add directory recursively to zip.
     *
     * @param ZipArchive $zip The zip archive to add files to
     * @param string $path The filesystem path to the directory
     * @param string $zipPath The path within the zip archive
     * @return void
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
     * Backup database to SQL file.
     *
     * Currently supports MySQL databases via mysqldump.
     *
     * @param string $outputPath Path to write the SQL backup file
     * @return void
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
                Log::info('Database backup skipped for non-MySQL database', [
                    'driver' => $dbConfig['driver'] ?? 'unknown',
                ]);
            }
        } catch (Exception $e) {
            Log::warning('Database backup failed', ['error' => $e->getMessage()]);
        }
    }
}
