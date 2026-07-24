<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TrackingType;
use App\Models\Inventory\Product;
use App\Models\User;
use App\Services\TrackedStockReconciliationService;
use Illuminate\Console\Command;

/**
 * Reports (and with --fix, corrects) drift between products.stock and the
 * batch/serial tracking records. Read-only by default: it never changes
 * stock unless --fix is passed, and even then only through the audited
 * recount adjustment path.
 */
class ReconcileTrackedStockCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inventory:reconcile-tracked-stock
                            {--org= : Only reconcile a single organization}
                            {--user= : Attribute --fix adjustments to this user id (defaults to each org\'s first user)}
                            {--fix : Correct products.stock to match the tracked batch/serial records}';

    /**
     * @var string
     */
    protected $description = 'Report (and optionally correct) drift between products.stock and batch/serial tracking records';

    public function handle(TrackedStockReconciliationService $service): int
    {
        $orgIds = $this->option('org') !== null
            ? [(int) $this->option('org')]
            : Product::query()
                ->whereIn('tracking_type', [TrackingType::BATCH->value, TrackingType::SERIAL->value])
                ->distinct()
                ->pluck('organization_id')
                ->all();

        $totalDrift = 0;
        $totalFixed = 0;

        foreach ($orgIds as $orgId) {
            $rows = $service->discrepancies((int) $orgId);

            if ($rows->isEmpty()) {
                continue;
            }

            $totalDrift += $rows->count();

            $this->info("Organization {$orgId}: {$rows->count()} product(s) out of sync");
            $this->table(
                ['SKU', 'Product', 'Type', 'Recorded', 'Tracked', 'Diff'],
                $rows->map(fn (array $r): array => [
                    $r['product']->sku,
                    $r['product']->name,
                    $r['tracking_type'],
                    $r['recorded_stock'],
                    $r['tracked_total'],
                    sprintf('%+d', $r['difference']),
                ])->all()
            );

            if ($this->option('fix')) {
                $actor = $this->resolveActor((int) $orgId);

                if ($actor === null) {
                    $this->warn("  No user to attribute adjustments to for organization {$orgId} — skipping fix.");

                    continue;
                }

                $totalFixed += $service->reconcile((int) $orgId, $actor);
            }
        }

        if ($totalDrift === 0) {
            $this->info('All batch/serial-tracked products are in sync with their records.');

            return Command::SUCCESS;
        }

        if ($this->option('fix')) {
            $this->info("Corrected {$totalFixed} product(s) to match tracked records.");
        } else {
            $this->warn("{$totalDrift} product(s) drifted. Re-run with --fix to correct them via audited adjustments.");
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve the user that --fix adjustments are attributed to: the
     * explicit --user if given (validated against the org), otherwise the
     * organization's first user.
     */
    private function resolveActor(int $organizationId): ?User
    {
        if ($this->option('user') !== null) {
            return User::where('organization_id', $organizationId)
                ->whereKey((int) $this->option('user'))
                ->first();
        }

        return User::where('organization_id', $organizationId)
            ->orderBy('id')
            ->first();
    }
}
