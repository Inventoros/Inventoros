<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UpdateService;
use Illuminate\Console\Command;

/**
 * Console command for managing application updates from GitHub releases.
 *
 * Supports checking for updates, creating backups, listing backups,
 * and restoring from backups.
 */
class UpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update
                            {--check : Only check if an update is available}
                            {--backup : Only create a backup}
                            {--list-backups : List all available backups}
                            {--restore= : Restore from a specific backup file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the application from GitHub releases';

    /**
     * The update service instance.
     *
     * @var UpdateService
     */
    protected UpdateService $updateService;

    /**
     * Create a new command instance.
     *
     * @param UpdateService $updateService
     */
    public function __construct(UpdateService $updateService)
    {
        parent::__construct();
        $this->updateService = $updateService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Handle --check option
        if ($this->option('check')) {
            return $this->checkForUpdates();
        }

        // Handle --backup option
        if ($this->option('backup')) {
            return $this->createBackup();
        }

        // Handle --list-backups option
        if ($this->option('list-backups')) {
            return $this->listBackups();
        }

        // Handle --restore option
        if ($backupFile = $this->option('restore')) {
            return $this->restoreBackup($backupFile);
        }

        // Perform update
        return $this->performUpdate();
    }

    /**
     * Check if an update is available
     */
    protected function checkForUpdates(): int
    {
        $this->info('Checking for updates...');

        $currentVersion = $this->updateService->getCurrentVersion();
        $this->line("Current version: <fg=cyan>{$currentVersion}</>");

        $latestRelease = $this->updateService->getLatestRelease();

        if (!$latestRelease) {
            $this->error('Could not fetch latest release information from GitHub.');
            return self::FAILURE;
        }

        $latestVersion = $latestRelease['version'];
        $this->line("Latest version: <fg=cyan>{$latestVersion}</>");

        if ($this->updateService->isUpdateAvailable()) {
            $this->info('✓ An update is available!');
            $this->newLine();
            $this->line("<fg=yellow>Release: {$latestRelease['name']}</>");
            if ($latestRelease['body']) {
                $this->newLine();
                $this->line('<fg=yellow>Release Notes:</>');
                $this->line($latestRelease['body']);
            }
            $this->newLine();
            $this->line('Run <fg=cyan>php artisan app:update</> to install the update.');
            return self::SUCCESS;
        }

        $this->info('✓ You are running the latest version.');
        return self::SUCCESS;
    }

    /**
     * Create a backup
     */
    protected function createBackup(): int
    {
        $this->info('Creating backup...');

        try {
            $backupPath = $this->updateService->createBackup();
            $this->info("✓ Backup created successfully: {$backupPath}");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Backup failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * List available backups
     */
    protected function listBackups(): int
    {
        $backups = $this->updateService->listBackups();

        if (empty($backups)) {
            $this->info('No backups found.');
            return self::SUCCESS;
        }

        $this->info('Available backups:');
        $this->newLine();

        $rows = [];
        foreach ($backups as $backup) {
            $rows[] = [
                $backup['filename'],
                $this->formatBytes($backup['size']),
                date('Y-m-d H:i:s', $backup['created_at']),
            ];
        }

        $this->table(['Filename', 'Size', 'Created At'], $rows);

        $this->newLine();
        $this->line('To restore a backup, run:');
        $this->line('<fg=cyan>php artisan app:update --restore=backup_filename.zip</>');

        return self::SUCCESS;
    }

    /**
     * Restore from backup
     */
    protected function restoreBackup(string $backupFile): int
    {
        // Check if it's a full path or just a filename
        if (!file_exists($backupFile)) {
            $backupFile = storage_path("app/backups/{$backupFile}");
        }

        if (!file_exists($backupFile)) {
            $this->error('Backup file not found.');
            return self::FAILURE;
        }

        if (!$this->confirm("Are you sure you want to restore from this backup? This will overwrite the current installation.")) {
            $this->info('Restore cancelled.');
            return self::SUCCESS;
        }

        $this->warn('Starting restore process...');

        $result = $this->updateService->restoreFromBackup($backupFile);

        if ($result['success']) {
            $this->info('✓ ' . $result['message']);
            return self::SUCCESS;
        }

        $this->error('✗ ' . $result['message']);
        return self::FAILURE;
    }

    /**
     * Perform the update
     */
    protected function performUpdate(): int
    {
        $this->info('Starting update process...');
        $this->newLine();

        // Check for updates first
        if (!$this->updateService->isUpdateAvailable()) {
            $this->info('You are already running the latest version.');
            return self::SUCCESS;
        }

        $latestRelease = $this->updateService->getLatestRelease();
        $this->line("Updating from <fg=cyan>{$this->updateService->getCurrentVersion()}</> to <fg=cyan>{$latestRelease['version']}</>");
        $this->newLine();

        if (!$this->confirm('Do you want to proceed with the update?', true)) {
            $this->info('Update cancelled.');
            return self::SUCCESS;
        }

        $this->newLine();

        // Perform update with progress callback
        $result = $this->updateService->update(null, function ($message) {
            $this->line("  → {$message}");
        });

        $this->newLine();

        if ($result['success']) {
            $this->info('✓ ' . $result['message']);

            if (isset($result['backup_path'])) {
                $this->line("Backup saved to: <fg=cyan>{$result['backup_path']}</>");
            }

            $this->newLine();
            $this->line('<fg=yellow>Please restart any queue workers or background processes.</>');

            return self::SUCCESS;
        }

        $this->error('✗ ' . $result['message']);
        return self::FAILURE;
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
