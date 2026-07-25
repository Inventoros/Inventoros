<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProductLocationStockService;
use Illuminate\Console\Command;

/**
 * Seeds per-location stock rows for products that have a location but no row
 * yet. The create_product_location_stocks migration runs this once; this
 * command exists to re-run it safely if products were assigned a location
 * after the migration, or to repair drift. Idempotent.
 */
class BackfillLocationStockCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inventory:backfill-location-stock
                            {--org= : Only backfill a single organization}';

    /**
     * @var string
     */
    protected $description = 'Seed per-location stock rows from products\' assigned locations (idempotent)';

    public function handle(ProductLocationStockService $service): int
    {
        $orgId = $this->option('org') !== null ? (int) $this->option('org') : null;

        $created = $service->backfill($orgId);

        $this->info("Backfilled {$created} product-location stock row(s).");

        return Command::SUCCESS;
    }
}
