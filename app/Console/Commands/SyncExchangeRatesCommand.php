<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncExchangeRatesJob;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Services\ExchangeRates\ExchangeRateSynchronizer;
use Illuminate\Console\Command;

/**
 * Queues the exchange rate synchronization of every organization that is
 * due (automatic sync on, its frequency or back-off elapsed). Scheduled
 * hourly; safe to run by hand. --now runs it in this process instead of the
 * queue, --org limits it to one organization, --force ignores the schedule.
 */
class SyncExchangeRatesCommand extends Command
{
    protected $signature = 'exchange-rates:sync
        {--org= : Only this organization}
        {--force : Sync even when not due (and even with automatic sync off)}
        {--now : Run in this process instead of queueing}';

    protected $description = 'Fetch the latest exchange rates for organizations whose automatic sync is due';

    public function handle(ExchangeRateSynchronizer $synchronizer): int
    {
        if ($this->option('force')) {
            $query = ExchangeRateSetting::query()->withoutGlobalScopes();

            if ($this->option('org') !== null) {
                $query->where('organization_id', (int) $this->option('org'));
            } else {
                $query->where('sync_enabled', true);
            }

            $due = $query->get();
        } else {
            $due = $synchronizer->due();

            if ($this->option('org') !== null) {
                $due = $due->where('organization_id', (int) $this->option('org'))->values();
            }
        }

        if ($due->isEmpty()) {
            $this->info('No organization is due for an exchange rate sync.');

            return self::SUCCESS;
        }

        if ($this->option('now')) {
            $outcomes = $synchronizer->run($due);

            foreach ($outcomes as $organizationId => $outcome) {
                $this->line("Organization {$organizationId}: {$outcome['status']}".($outcome['error'] ? " ({$outcome['error']})" : ''));
            }

            return self::SUCCESS;
        }

        $ids = $due->pluck('organization_id')->map(fn ($id) => (int) $id)->values()->all();

        // Claim the run so the next hourly pass does not queue these again
        // while the job waits; the job sets the real next attempt.
        if (! $this->option('force')) {
            ExchangeRateSetting::query()->withoutGlobalScopes()
                ->whereIn('organization_id', $ids)
                ->update(['next_sync_at' => now()->addMinutes(30)]);
        }

        SyncExchangeRatesJob::dispatch($ids);

        $this->info("Queued the exchange rate sync of {$due->count()} organization(s).");

        return self::SUCCESS;
    }
}
