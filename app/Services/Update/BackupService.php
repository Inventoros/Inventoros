<?php

namespace App\Services\Update;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class BackupService
{
    protected string $backupPath;

    public function __construct()
    {
        $this->backupPath = storage_path('app/backups');
    }

    /**
     * Create a backup of the current installation
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

    /**
     * Delete a backup file
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
     * Get backup path
     */
    public function getBackupPath(): string
    {
        return $this->backupPath;
    }

    /**
     * Ensure backup directory exists
     */
    protected function ensureBackupDirectoryExists(): void
    {
        if (!File::exists($this->backupPath)) {
            File::makeDirectory($this->backupPath, config('limits.permissions.directory'), true);
        }
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
}
