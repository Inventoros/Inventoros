<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\RunInventoryReset;
use ReflectionClass;
use Tests\TestCase;

final class QueueReservationTimeoutTest extends TestCase
{
    public function test_queue_reservations_outlast_every_declared_job_timeout(): void
    {
        $longestTimeout = 0;

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $job = new ReflectionClass('App\\Jobs\\'.pathinfo($file, PATHINFO_FILENAME));
            // This job always selects its own database queue, including when
            // dispatched outside the HTTP controller. Check it separately.
            if ($job->getName() === RunInventoryReset::class) {
                continue;
            }
            $longestTimeout = max($longestTimeout, (int) ($job->getDefaultProperties()['timeout'] ?? 0));
        }

        $this->assertGreaterThan(0, $longestTimeout, 'Expected queued jobs with explicit timeouts.');

        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan(
                $longestTimeout,
                config("queue.connections.{$connection}.retry_after"),
                "The {$connection} queue can redeliver a job while its first execution is still running."
            );
        }
    }

    public function test_the_reset_job_uses_a_dedicated_queue_with_a_long_enough_reservation(): void
    {
        $job = new RunInventoryReset(0);
        $this->assertSame('inventory-resets', $job->connection);
        $this->assertSame('inventory-resets', $job->queue);
        $this->assertSame('database', config('queue.connections.'.$job->connection.'.driver'));
        $this->assertNull(config('queue.connections.'.$job->connection.'.connection'));
        $this->assertGreaterThan($job->timeout, config('queue.connections.'.$job->connection.'.retry_after'));
    }
}
