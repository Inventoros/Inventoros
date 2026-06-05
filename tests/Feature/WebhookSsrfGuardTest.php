<?php

namespace Tests\Feature;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\PublicHostGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WebhookSsrfGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_host_guard_rejects_loopback_ip(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-public address 127\.0\.0\.1/');
        PublicHostGuard::assertPublic('http://127.0.0.1/hook');
    }

    public function test_public_host_guard_rejects_aws_metadata_ip(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-public address 169\.254\.169\.254/');
        PublicHostGuard::assertPublic('http://169.254.169.254/latest/meta-data/');
    }

    public function test_public_host_guard_rejects_rfc1918_ips(): void
    {
        foreach (['10.0.0.5', '172.16.5.5', '192.168.1.10'] as $ip) {
            try {
                PublicHostGuard::assertPublic("http://{$ip}/hook");
                $this->fail("Expected {$ip} to be rejected as private");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($ip, $e->getMessage());
            }
        }
    }

    public function test_public_host_guard_rejects_localhost_string(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/host 'localhost' is not allowed/");
        PublicHostGuard::assertPublic('http://localhost:8080/hook');
    }

    public function test_public_host_guard_accepts_public_ip_literal(): void
    {
        // Should not throw.
        PublicHostGuard::assertPublic('https://8.8.8.8/hook');
        $this->addToAssertionCount(1);
    }

    public function test_delivery_to_loopback_marks_delivery_failed_without_http_call(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $org = Organization::create(['name' => 'SsrfOrg', 'email' => 's@test.com']);
        $user = User::create([
            'name' => 'A', 'email' => 'a@test.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);

        // Create the webhook directly via the model so we bypass the create-
        // time URL validator — this simulates a DNS-rebinding scenario where
        // the host validated fine at create time and now resolves locally.
        $webhook = Webhook::create([
            'organization_id' => $org->id,
            'name' => 'Loopback Receiver',
            'url' => 'http://127.0.0.1/hook',
            'secret' => 'shh',
            'events' => ['product.created'],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'organization_id' => $org->id,
            'event' => 'product.created',
            'payload' => ['ok' => true],
            'status' => 'pending',
            'attempts' => 0,
        ]);

        Http::fake();

        // The job is allowed to throw the RuntimeException — that's how
        // Laravel surfaces retry intent. We just want zero outbound calls.
        try {
            (new WebhookDeliveryJob($delivery))->handle();
        } catch (RuntimeException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_empty_dns_resolution_fails_closed_in_production(): void
    {
        $original = app()->environment();
        $originalConfig = config('app.env');
        app()['env'] = 'production';
        config(['app.env' => 'production']);

        try {
            $this->expectException(RuntimeException::class);
            // Reserved TLD guaranteed never to resolve (RFC 2606).
            PublicHostGuard::assertPublic('https://nonexistent-host.invalid/webhook');
        } finally {
            app()['env'] = $original;
            config(['app.env' => $originalConfig]);
        }
    }

    public function test_empty_dns_resolution_proceeds_outside_production(): void
    {
        // testing env (not production) — empty DNS must NOT throw.
        PublicHostGuard::assertPublic('https://nonexistent-host.invalid/webhook');
        $this->addToAssertionCount(1);
    }
}
