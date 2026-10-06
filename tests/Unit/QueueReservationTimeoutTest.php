<?php

declare(strict_types=1);

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

final class QueueReservationTimeoutTest extends TestCase
{
    public function test_queue_reservations_outlast_every_declared_job_timeout(): void
    {
        $longestTimeout = 0;

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $job = new ReflectionClass('App\\Jobs\\'.pathinfo($file, PATHINFO_FILENAME));
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
}
