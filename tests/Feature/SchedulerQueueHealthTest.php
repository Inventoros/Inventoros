<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Support\SchedulerHealth;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * cPanel installs have no queue worker: the scheduler drains the database
 * queue, records a heartbeat, and admins are warned on the dashboard when
 * the scheduler has stopped or jobs are piling up.
 */
class SchedulerQueueHealthTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledEvent(callable $match): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if ($match($event)) {
                return $event;
            }
        }

        return null;
    }

    private function queueWorkerEvent(): ?Event
    {
        return $this->scheduledEvent(fn (Event $e) => str_contains((string) $e->command, 'queue:work'));
    }

    public function test_the_scheduler_runs_a_short_lived_queue_worker_every_minute(): void
    {
        config(['queue.run_via_scheduler' => true]);

        $event = $this->queueWorkerEvent();

        $this->assertNotNull($event, 'queue:work is not scheduled');
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time=50', $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_the_scheduled_queue_worker_is_skipped_when_the_flag_is_off(): void
    {
        config(['queue.run_via_scheduler' => false]);

        $this->assertFalse($this->queueWorkerEvent()->filtersPass($this->app));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
     */
    public static function flagDefaults(): array
    {
        return [
            'database queue' => ['database', null, true],
            'sync queue' => ['sync', null, false],
            'redis queue' => ['redis', null, false],
            'explicit off' => ['database', 'false', false],
            'explicit on' => ['sync', 'true', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flagDefaults')]
    public function test_the_flag_defaults_on_only_for_the_database_queue(string $connection, ?string $flag, bool $expected): void
    {
        $saved = [];
        $set = function (string $key, ?string $value) use (&$saved) {
            $saved[$key] ??= [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        };

        try {
            $set('QUEUE_CONNECTION', $connection);
            $set('QUEUE_RUN_VIA_SCHEDULER', $flag);

            $config = require config_path('queue.php');

            $this->assertSame($expected, $config['run_via_scheduler']);
        } finally {
            foreach ($saved as $key => [$server, $env, $put]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                $put === false ? putenv($key) : putenv("{$key}={$put}");
            }
        }
    }

    public function test_the_scheduler_records_a_heartbeat_every_minute(): void
    {
        $event = $this->scheduledEvent(fn (Event $e) => $e instanceof CallbackEvent && $e->description === SchedulerHealth::HEARTBEAT_EVENT);

        $this->assertNotNull($event, 'heartbeat is not scheduled');
        $this->assertSame('* * * * *', $event->expression);

        $this->assertNull(app(SchedulerHealth::class)->lastHeartbeat());

        $event->run($this->app);

        $this->assertNotNull(app(SchedulerHealth::class)->lastHeartbeat());
    }

    public function test_warns_when_the_scheduler_has_never_run(): void
    {
        $this->assertSame(['scheduler_stale'], array_column(app(SchedulerHealth::class)->warnings(), 'type'));
    }

    public function test_no_warnings_when_the_heartbeat_is_fresh_and_the_queue_is_clear(): void
    {
        app(SchedulerHealth::class)->beat();

        $this->assertSame([], app(SchedulerHealth::class)->warnings());
    }

    public function test_warns_when_the_heartbeat_is_older_than_ten_minutes(): void
    {
        Cache::forever(SchedulerHealth::HEARTBEAT_KEY, now()->subMinutes(11)->getTimestamp());

        $warnings = app(SchedulerHealth::class)->warnings();

        $this->assertSame('scheduler_stale', $warnings[0]['type']);
        $this->assertNotNull($warnings[0]['last_run']);
    }

    public function test_warns_about_database_jobs_waiting_longer_than_fifteen_minutes(): void
    {
        config(['queue.default' => 'database']);
        app(SchedulerHealth::class)->beat();

        $job = ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null];
        DB::table('jobs')->insert($job + ['available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);
        DB::table('jobs')->insert($job + ['available_at' => now()->subMinutes(16)->getTimestamp(), 'created_at' => now()->subMinutes(16)->getTimestamp()]);
        // Recent, and delayed-until-later jobs are not a backlog.
        DB::table('jobs')->insert($job + ['available_at' => now()->subMinute()->getTimestamp(), 'created_at' => now()->subMinute()->getTimestamp()]);
        DB::table('jobs')->insert($job + ['available_at' => now()->addHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp()]);

        $warnings = app(SchedulerHealth::class)->warnings();

        $this->assertSame([['type' => 'queue_backlog', 'count' => 2]], $warnings);
    }

    public function test_the_jobs_table_is_ignored_for_other_queue_drivers(): void
    {
        config(['queue.default' => 'sync']);
        app(SchedulerHealth::class)->beat();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp()]);

        $this->assertSame([], app(SchedulerHealth::class)->warnings());
    }

    public function test_warns_about_jobs_that_failed_in_the_last_day(): void
    {
        app(SchedulerHealth::class)->beat();
        $failed = ['connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom'];
        DB::table('failed_jobs')->insert($failed + ['uuid' => 'a', 'failed_at' => now()->subHour()]);
        DB::table('failed_jobs')->insert($failed + ['uuid' => 'b', 'failed_at' => now()->subDays(3)]);

        $this->assertSame([['type' => 'failed_jobs', 'count' => 1]], app(SchedulerHealth::class)->warnings());
    }

    public function test_admins_see_the_warnings_on_the_dashboard_and_others_do_not(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $org = Organization::create(['name' => 'Health Org', 'email' => 'h@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create(['name' => 'Ada', 'email' => 'ada@h.test', 'password' => bcrypt('x'), 'organization_id' => $org->id, 'role' => 'admin']);
        $member = User::create(['name' => 'Mo', 'email' => 'mo@h.test', 'password' => bcrypt('x'), 'organization_id' => $org->id, 'role' => 'member']);

        $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('systemWarnings.0.type', 'scheduler_stale'));

        $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('systemWarnings', []));
    }
}
