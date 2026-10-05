<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Auth\Organization;
use App\Services\Marketplace\PluginLicenceService;
use Illuminate\Console\Command;

/**
 * Refreshes the signed plugin entitlements of every organization connected
 * to the marketplace. Scheduled daily; safe to run by hand.
 */
class RefreshMarketplaceEntitlementsCommand extends Command
{
    protected $signature = 'marketplace:refresh-entitlements {--org= : Only refresh one organization}';

    protected $description = 'Refresh the signed paid-plugin licences of organizations connected to the marketplace';

    public function handle(PluginLicenceService $licences): int
    {
        $query = Organization::query()->whereNotNull('marketplace_token');

        if ($this->option('org') !== null) {
            $query->whereKey((int) $this->option('org'));
        }

        $refreshed = 0;
        $failed = 0;

        foreach ($query->get() as $organization) {
            $licences->refresh($organization) ? $refreshed++ : $failed++;
        }

        $this->info("Refreshed {$refreshed} organization(s); {$failed} could not be refreshed (their last licences stay in force for the grace period).");

        return self::SUCCESS;
    }
}
