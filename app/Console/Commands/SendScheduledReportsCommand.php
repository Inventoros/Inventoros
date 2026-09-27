<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reports\ScheduledReportRunner;
use Illuminate\Console\Command;

/**
 * Email every saved report whose schedule is due. Registered in
 * routes/console.php to run every fifteen minutes; see ScheduledReportRunner
 * for the claim, permission re-check and recipient rules.
 */
class SendScheduledReportsCommand extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Generate and queue the saved reports whose delivery schedule is due';

    public function handle(ScheduledReportRunner $runner): int
    {
        $stats = $runner->runDue(now());

        $this->info(sprintf(
            'Scheduled reports: %d due, %d sent, %d skipped, %d failed.',
            $stats['due'], $stats['sent'], $stats['skipped'], $stats['failed']
        ));

        return self::SUCCESS;
    }
}
