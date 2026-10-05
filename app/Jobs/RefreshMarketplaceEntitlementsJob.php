<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Auth\Organization;
use App\Services\Marketplace\PluginLicenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Refreshes one organization's signed plugin entitlements from the
 * marketplace. Queued by plugin_licence() when the stored document is stale,
 * so reading a licence never waits on the network. A failed refresh keeps
 * the previous document (the offline grace period covers it) and is not
 * retried here: the next stale read or the daily schedule tries again.
 */
final class RefreshMarketplaceEntitlementsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $organizationId) {}

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(PluginLicenceService $licences): void
    {
        $organization = Organization::find($this->organizationId);

        if ($organization !== null) {
            $licences->refresh($organization);
        }
    }
}
