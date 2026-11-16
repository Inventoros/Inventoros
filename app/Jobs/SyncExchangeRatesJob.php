<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Services\ExchangeRates\ExchangeRateSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Synchronizes the exchange rates of the organizations the scheduled
 * exchange-rates:sync run found due, in one pass so identical requests are
 * made once. Not retried by the queue: a failure is recorded on each
 * organization with its next attempt (backing off), and the hourly schedule
 * picks it up again.
 */
final class SyncExchangeRatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    /**
     * @param  array<int, int>  $organizationIds
     */
    public function __construct(public readonly array $organizationIds) {}

    public function uniqueId(): string
    {
        $ids = $this->organizationIds;
        sort($ids);

        return sha1(implode(',', $ids));
    }

    public function handle(ExchangeRateSynchronizer $synchronizer): void
    {
        $settings = ExchangeRateSetting::query()->withoutGlobalScopes()
            ->whereIn('organization_id', $this->organizationIds)
            ->get();

        if ($settings->isNotEmpty()) {
            $synchronizer->run($settings);
        }
    }
}
