<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the background machinery is actually running.
 *
 * A scheduled closure records a heartbeat every minute. On shared hosting
 * nothing else tells an admin that the cron job was never added, or that
 * queued mail and webhooks are piling up, so the dashboard shows the
 * warnings() returned here.
 */
class SchedulerHealth
{
    public const HEARTBEAT_KEY = 'inventoros:scheduler:heartbeat';

    public const HEARTBEAT_EVENT = 'inventoros:scheduler-heartbeat';

    /** The scheduler runs every minute; allow for a few missed runs. */
    public const STALE_AFTER_MINUTES = 10;

    /** A job that has waited this long is not being processed. */
    public const BACKLOG_AFTER_MINUTES = 15;

    /** Failed jobs within this window are reported. */
    public const FAILED_WITHIN_HOURS = 24;

    public function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp());
    }

    public function lastHeartbeat(): ?Carbon
    {
        $timestamp = Cache::get(self::HEARTBEAT_KEY);

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    /**
     * @return array<int, array{type: string, last_run?: string|null, count?: int}>
     */
    public function warnings(): array
    {
        $warnings = [];

        $last = $this->lastHeartbeat();
        if ($last === null || $last->lessThan(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            $warnings[] = ['type' => 'scheduler_stale', 'last_run' => $last?->toIso8601String()];
        }

        $backlog = $this->backlogCount();
        if ($backlog > 0) {
            $warnings[] = ['type' => 'queue_backlog', 'count' => $backlog];
        }

        $failed = $this->recentFailedCount();
        if ($failed > 0) {
            $warnings[] = ['type' => 'failed_jobs', 'count' => $failed];
        }

        return $warnings;
    }

    /**
     * Jobs on the database queue that became available more than
     * BACKLOG_AFTER_MINUTES ago and are still there. Other drivers keep their
     * jobs elsewhere, so there is nothing to count.
     */
    private function backlogCount(): int
    {
        $default = (string) config('queue.default');
        $connection = (array) config("queue.connections.{$default}", []);

        if (($connection['driver'] ?? null) !== 'database') {
            return 0;
        }

        return $this->safeCount(function () use ($connection) {
            $db = $connection['connection'] ?? null;
            $table = (string) ($connection['table'] ?? 'jobs');

            if (! Schema::connection($db)->hasTable($table)) {
                return 0;
            }

            return DB::connection($db)->table($table)
                ->where('available_at', '<', now()->subMinutes(self::BACKLOG_AFTER_MINUTES)->getTimestamp())
                ->count();
        });
    }

    private function recentFailedCount(): int
    {
        $driver = (string) config('queue.failed.driver');

        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return 0;
        }

        return $this->safeCount(function () {
            $db = config('queue.failed.database');
            $table = (string) config('queue.failed.table', 'failed_jobs');

            if (! Schema::connection($db)->hasTable($table)) {
                return 0;
            }

            return DB::connection($db)->table($table)
                ->where('failed_at', '>=', now()->subHours(self::FAILED_WITHIN_HOURS))
                ->count();
        });
    }

    /**
     * A health check must never take the dashboard down with it.
     */
    private function safeCount(\Closure $count): int
    {
        try {
            return (int) $count();
        } catch (\Throwable $e) {
            Log::warning('Could not check the queue for the dashboard health warning', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
