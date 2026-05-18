<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/**
 * Delete activity-log rows older than the configured retention window.
 *
 * activity_logs accumulates one row per Eloquent create/update/delete
 * across the entire tenant base. Without pruning the table grows
 * unbounded and the admin activity view's ORDER BY created_at scan
 * gets slower forever. Activity log rows also carry IP, user-agent,
 * and per-action `properties` blobs which sometimes include PII
 * (e.g., customer email on an order edit) — keeping them indefinitely
 * is a PIPEDA / GDPR liability.
 *
 * Default retention is 365 days. Override with --older-than=<days>.
 */
class PruneActivityLogsCommand extends Command
{
    protected $signature = 'activity-logs:prune
        {--older-than=365 : Delete rows older than this many days}
        {--dry-run : Report the count that would be deleted without deleting}';

    protected $description = 'Delete activity_logs rows older than the retention window (default 365 days)';

    public function handle(): int
    {
        $days = (int) $this->option('older-than');
        if ($days < 1) {
            $this->error('--older-than must be at least 1 day');
            return Command::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $count = ActivityLog::where('created_at', '<', $cutoff)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} activity_logs rows older than {$cutoff->toDateTimeString()} ({$days} days)");
            return Command::SUCCESS;
        }

        if ($count === 0) {
            $this->info('No activity_logs rows to prune.');
            return Command::SUCCESS;
        }

        $deleted = ActivityLog::where('created_at', '<', $cutoff)->delete();
        $this->info("Pruned {$deleted} activity_logs rows older than {$cutoff->toDateTimeString()} ({$days} days).");

        return Command::SUCCESS;
    }
}
