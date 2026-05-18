<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * Delete webhook_deliveries rows older than the configured retention
 * window. Every dispatched event creates a row; success rows are kept
 * forever today. After a few months in production the table runs into
 * the millions, the deliveries page ORDER BY scans seq, and backups
 * balloon. response_body stores up to 5 KB per failure too.
 *
 * Default retention: 30 days. By default we KEEP failed deliveries
 * (status='failed') indefinitely so the operator can diagnose
 * problems; pass --include-failed to prune those too.
 */
class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:prune
        {--older-than=30 : Delete rows older than this many days}
        {--include-failed : Also delete rows with status=failed (off by default)}
        {--dry-run : Report the count that would be deleted without deleting}';

    protected $description = 'Delete webhook_deliveries rows older than the retention window (default 30 days, preserves failed)';

    public function handle(): int
    {
        $days = (int) $this->option('older-than');
        if ($days < 1) {
            $this->error('--older-than must be at least 1 day');
            return Command::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = WebhookDelivery::where('created_at', '<', $cutoff);
        if (!$this->option('include-failed')) {
            $query->where('status', '!=', WebhookDelivery::STATUS_FAILED);
        }

        $count = $query->count();

        if ($this->option('dry-run')) {
            $scope = $this->option('include-failed') ? 'including' : 'excluding';
            $this->info("Would delete {$count} webhook_deliveries rows older than {$cutoff->toDateTimeString()} ({$days} days), {$scope} failed.");
            return Command::SUCCESS;
        }

        if ($count === 0) {
            $this->info('No webhook_deliveries rows to prune.');
            return Command::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Pruned {$deleted} webhook_deliveries rows older than {$cutoff->toDateTimeString()} ({$days} days).");

        return Command::SUCCESS;
    }
}
