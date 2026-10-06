<?php

use App\Support\SchedulerHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:check-reorder-points')->dailyAt('06:00');

// Drain the database queue (mail, webhooks) on hosts with no queue worker.
// Each run stops taking new jobs after 50 seconds, then finishes its current
// job (which may take up to 600 seconds). withoutOverlapping prevents another
// scheduled worker starting meanwhile. See config/queue.php `run_via_scheduler`.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('queue.run_via_scheduler'));

// Heartbeat for the dashboard's "scheduler is not running" warning.
Schedule::call(fn () => app(SchedulerHealth::class)->beat())
    ->everyMinute()
    ->name(SchedulerHealth::HEARTBEAT_EVENT);

// Scheduled cycle counts: turn every due schedule into a draft stock audit.
// Hourly, so a schedule's run time is honoured to within the hour.
Schedule::command('inventory:run-cycle-counts')->hourly()->withoutOverlapping();

// Scheduled saved-report emails. Schedules are set to the minute in the
// organization's time zone; each run sends whatever has come due since the
// last one and claims it, so overlapping runs never double-send.
Schedule::command('reports:send-scheduled')->everyFifteenMinutes()->withoutOverlapping();

// Carrier tracking poll for in-transit shipments (backstop for the EasyPost
// webhook). Rate-limited per organization inside the command.
Schedule::command('shipping:track')->everyThirtyMinutes()->withoutOverlapping();

// Retention pruning — see PruneActivityLogsCommand and
// PruneWebhookDeliveriesCommand for the rationale (PII, table-size
// runaway). Run nightly at off-peak times. Defaults are conservative:
// 365 days for activity logs, 30 days for non-failed webhook
// deliveries; operators can override per their compliance policy.
Schedule::command('activity-logs:prune')->dailyAt('03:00');
Schedule::command('webhooks:prune')->dailyAt('03:30');

// Paid plugin licences: refresh each connected organization's signed
// entitlements from the marketplace once a day.
Schedule::command('marketplace:refresh-entitlements')->dailyAt('04:15')->withoutOverlapping();
