<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CycleCountService;
use Illuminate\Console\Command;

/**
 * Turns every due cycle count schedule into a draft "cycle" stock audit and
 * notifies its assignee. Scheduled hourly in routes/console.php.
 */
class RunCycleCountsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inventory:run-cycle-counts';

    /**
     * @var string
     */
    protected $description = 'Create draft stock audits for cycle count schedules that are due';

    public function handle(CycleCountService $cycleCounts): int
    {
        $created = $cycleCounts->runDue();

        $this->info("Created {$created} cycle count audit(s).");

        return self::SUCCESS;
    }
}
