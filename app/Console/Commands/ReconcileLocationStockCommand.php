<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Inventory\Product;
use App\Services\LocationStockReconciliationService;
use Illuminate\Console\Command;

/**
 * Reports (and with --fix, corrects) drift between products.stock and the sum
 * of a product's per-location bins. Read-only by default: it never changes the
 * bins unless --fix is passed, and never changes products.stock.
 */
class ReconcileLocationStockCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inventory:reconcile-location-stock
                            {--org= : Only reconcile a single organization}
                            {--fix : Correct SUM(bins) to match products.stock}';

    /**
     * @var string
     */
    protected $description = 'Report (and optionally correct) drift between products.stock and the sum of its location bins';

    public function handle(LocationStockReconciliationService $service): int
    {
        $orgIds = $this->option('org') !== null
            ? [(int) $this->option('org')]
            : Product::query()->whereHas('locationStocks')->distinct()->pluck('organization_id')->all();

        $totalDrift = 0;
        $totalFixed = 0;

        foreach ($orgIds as $orgId) {
            $rows = $service->discrepancies((int) $orgId);

            if ($rows->isEmpty()) {
                continue;
            }

            $totalDrift += $rows->count();

            $this->info("Organization {$orgId}: {$rows->count()} product(s) whose bins do not sum to stock");
            $this->table(
                ['SKU', 'Product', 'Stock', 'Binned', 'Diff', 'Note'],
                $rows->map(fn (array $r): array => [
                    $r['product']->sku,
                    $r['product']->name,
                    $r['recorded_stock'],
                    $r['binned_total'],
                    sprintf('%+d', $r['difference']),
                    // difference = stock - binned: positive is unassigned stock,
                    // negative is a bin over-claiming what exists.
                    $r['difference'] > 0 ? 'unassigned' : 'over-claim',
                ])->all()
            );

            if ($this->option('fix')) {
                $totalFixed += $service->reconcile((int) $orgId);
            }
        }

        if ($totalDrift === 0) {
            $this->info('Every binned product sums to its recorded stock.');

            return Command::SUCCESS;
        }

        if ($this->option('fix')) {
            $this->info("Reconciled {$totalFixed} product(s) so their bins sum to stock.");
        } else {
            $this->warn("{$totalDrift} product(s) drifted. Re-run with --fix to reconcile the bins.");
        }

        return Command::SUCCESS;
    }
}
