<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackorderService;
use Illuminate\Console\Command;

/**
 * Safety-net sweep that fulfils any backorder whose stock is now available,
 * in case a stock-increase path did not trigger reconciliation directly.
 */
class ReconcileBackordersCommand extends Command
{
    protected $signature = 'inventory:reconcile-backorders';

    protected $description = 'Fulfil outstanding backorders for which stock is now available';

    public function handle(BackorderService $backorders): int
    {
        $fulfilled = $backorders->reconcileAll();

        $this->info("Reconciled {$fulfilled} backordered unit(s).");

        return Command::SUCCESS;
    }
}
