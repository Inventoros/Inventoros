<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Reports\ScheduledReportRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Generates one scheduled report and mails it to its recipients.
 *
 * The payload is only the schedule id and the recipient addresses: the
 * report is rendered here, in the worker, so report data never sits in the
 * jobs or failed_jobs table. Not retried, so a partial failure never mails
 * the same report twice; the schedule simply runs again at its next slot.
 */
final class DeliverScheduledReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, string>  $recipients
     */
    public function __construct(public int $scheduleId, public array $recipients) {}

    public function handle(ScheduledReportRunner $runner): void
    {
        $runner->deliver($this->scheduleId, $this->recipients);
    }
}
