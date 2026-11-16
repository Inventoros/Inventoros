<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UpdateService;
use Illuminate\Console\Command;

/**
 * Delete what update runs left in storage/app/temp: downloaded and extracted
 * release packages, signature files and unpacked backups from a restore.
 *
 * Updates remove their own files when they finish (whether they succeed or
 * fail), and leftovers older than a day are swept when an update or a backup
 * starts. This command clears the rest on demand, for example files left by
 * versions before that cleanup existed. Nothing else in the temp directory is
 * touched, and nothing is removed while an update is running.
 */
class CleanUpdateTempFilesCommand extends Command
{
    protected $signature = 'update:clean-temp
        {--older-than=0 : Only delete leftovers at least this many hours old}
        {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Delete leftover update downloads and extracted packages from storage/app/temp';

    public function handle(UpdateService $updates): int
    {
        $hours = $this->option('older-than');

        if (! is_numeric($hours) || (float) $hours < 0) {
            $this->error('--older-than must be a number of hours (0 or more).');

            return self::FAILURE;
        }

        $seconds = (int) round((float) $hours * 3600);
        $dryRun = (bool) $this->option('dry-run');

        $result = $updates->cleanTempFiles($seconds > 0 ? $seconds : null, $dryRun);

        if ($result === null) {
            $this->error('An update is in progress. Run this again once it has finished.');

            return self::FAILURE;
        }

        foreach ($result['removed'] as $name) {
            $this->line(($dryRun ? '  would delete ' : '  deleted ').$name);
        }

        foreach ($result['failed'] as $name) {
            $this->warn("  could not delete {$name}");
        }

        $count = count($result['removed']);
        $size = $this->formatBytes($result['bytes']);

        if ($count === 0 && $result['failed'] === []) {
            $this->info('No update temp files to delete.');
        } elseif ($dryRun) {
            $this->info("Would delete {$count} update temp ".($count === 1 ? 'entry' : 'entries')." ({$size}).");
        } else {
            $this->info("Deleted {$count} update temp ".($count === 1 ? 'entry' : 'entries')." ({$size}).");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;

        for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }

        return round($size, 1).' '.$units[$i];
    }
}
