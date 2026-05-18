<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:check-reorder-points')->dailyAt('06:00');

// Retention pruning — see PruneActivityLogsCommand and
// PruneWebhookDeliveriesCommand for the rationale (PII, table-size
// runaway). Run nightly at off-peak times. Defaults are conservative:
// 365 days for activity logs, 30 days for non-failed webhook
// deliveries; operators can override per their compliance policy.
Schedule::command('activity-logs:prune')->dailyAt('03:00');
Schedule::command('webhooks:prune')->dailyAt('03:30');
