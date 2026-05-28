<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SentryConfigTest extends TestCase
{
    public function test_sentry_is_disabled_by_default(): void
    {
        // With no DSN configured the integration is inert — nothing is sent.
        $this->assertNull(config('sentry.dsn'));
    }

    public function test_error_and_trace_sample_rate_defaults(): void
    {
        // Capture 100% of errors, sample 10% of performance traces.
        $this->assertSame(1.0, config('sentry.sample_rate'));
        $this->assertSame(0.1, config('sentry.traces_sample_rate'));
    }

    public function test_pii_is_not_sent_by_default(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    public function test_health_endpoint_transactions_are_ignored(): void
    {
        $this->assertContains('/up', config('sentry.ignore_transactions'));
    }

    public function test_sentry_service_provider_is_registered(): void
    {
        // package:discover wired the provider; the hub binding resolves.
        $this->assertTrue(app()->bound('sentry'));
    }
}
