<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use App\Support\PublicHostGuard;
use App\Support\PublicHostResolver;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

final class WebhookConnectionPinningTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    private function delivery(string $url): WebhookDelivery
    {
        $org = $this->makeOrganization('Pinned webhook');
        $staff = $this->makeStaff($org, 'pinning@example.test', 'admin');
        $webhook = Webhook::create([
            'organization_id' => $org->id, 'name' => 'Receiver', 'url' => $url,
            'secret' => 'pinning-test-secret', 'events' => ['product.created'],
            'is_active' => true, 'created_by' => $staff->id,
        ]);

        return WebhookDelivery::create([
            'webhook_id' => $webhook->id, 'organization_id' => $org->id,
            'event' => 'product.created', 'payload' => ['name' => 'Widget', 'url' => 'https://example.test/a/b'],
            'status' => 'pending', 'attempts' => 0,
        ]);
    }

    public function test_delivery_pins_one_checked_dns_answer_without_rewriting_the_hostname_or_body(): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->once()
            ->with('receiver.example.test')->andReturn(['8.8.8.8', '2606:4700:4700::1111']);
        $delivery = $this->delivery('https://receiver.example.test:8443/hook?tenant=one');
        Http::preventStrayRequests();
        Http::fake(function ($request, array $options) use ($delivery) {
            $this->assertSame($delivery->webhook->url, $request->url());
            $this->assertSame('receiver.example.test:8443', $request->header('Host')[0]);
            $this->assertSame(['receiver.example.test:8443:8.8.8.8,[2606:4700:4700::1111]'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
            $this->assertTrue($options['verify']);
            $this->assertTrue($options['curl'][CURLOPT_FRESH_CONNECT]);
            $this->assertTrue($options['curl'][CURLOPT_FORBID_REUSE]);
            $handler = (new \ReflectionProperty(HandlerStack::class, 'handler'))->getValue($options['handler']);
            $this->assertInstanceOf(CurlHandler::class, $handler);
            $this->assertSame(WebhookService::sign($request->body(), $delivery->webhook->secret), $request->header('X-Webhook-Signature')[0]);

            return Http::response('ok');
        });

        (new WebhookDeliveryJob($delivery))->handle();
        Http::assertSentCount(1);
        $this->assertSame('success', $delivery->fresh()->status);
    }

    public function test_each_retry_resolves_again_and_rejects_a_new_private_answer(): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->twice()
            ->with('receiver.example.test')->andReturn(['8.8.8.8'], ['127.0.0.1']);
        $delivery = $this->delivery('https://receiver.example.test/hook');
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('Unavailable', 503)]);
        $job = new WebhookDeliveryJob($delivery);
        try {
            $job->handle();
            $this->fail('The HTTP failure must request a retry.');
        } catch (\Exception $error) {
            $this->assertStringContainsString('503', $error->getMessage());
        }
        try {
            $job->handle();
            $this->fail('The rebound destination must be blocked.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('non-public address 127.0.0.1', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertNotNull($delivery->fresh()->next_retry_at);
    }

    public function test_a_mixed_public_private_dns_answer_sends_nothing(): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->once()
            ->andReturn(['8.8.8.8', '169.254.169.254']);
        $delivery = $this->delivery('https://receiver.example.test/hook');
        Http::fake();
        try {
            (new WebhookDeliveryJob($delivery))->handle();
            $this->fail('Every returned address must be public.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('169.254.169.254', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_a_public_receiver_redirect_cannot_forward_the_payload_to_metadata(): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->once()->andReturn(['8.8.8.8']);
        $delivery = $this->delivery('https://receiver.example.test/hook');
        Http::preventStrayRequests();
        Http::fake([
            'receiver.example.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('must not be requested'),
        ]);
        try {
            (new WebhookDeliveryJob($delivery))->handle();
            $this->fail('A redirect is a failed delivery, not a successful forwarded request.');
        } catch (\Exception $error) {
            $this->assertStringContainsString('302', $error->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_delivery_fails_closed_on_empty_dns_even_outside_production(): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->once()->andReturn([]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not resolve to any address');
        PublicHostGuard::connectionOptions('https://receiver.example.test/hook');
    }

    public function test_public_ipv6_literal_requires_no_dns_override(): void
    {
        $this->mock(PublicHostResolver::class)->shouldNotReceive('resolve');
        $options = PublicHostGuard::connectionOptions('https://[2606:4700:4700::1111]:8443/hook');
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_http_uses_the_actual_port_in_the_dns_pin(): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->once()->andReturn(['8.8.8.8']);
        $options = PublicHostGuard::connectionOptions('http://receiver.example.test/hook');
        $this->assertSame(['receiver.example.test:80:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsupported_schemes_and_alternate_ip_spellings_cannot_bypass_pinning(string $url): void
    {
        $this->mock(PublicHostResolver::class)->shouldNotReceive('resolve');
        $this->expectException(RuntimeException::class);
        PublicHostGuard::connectionOptions($url);
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], ['ftp://8.8.8.8/hook', 'http://2130706433/hook', 'http://0177.0.0.1/hook', 'http://0x7f000001/hook', 'http://[::1]/hook', 'http://localhost./hook', 'http://user@127.0.0.1/hook']);
    }

    #[DataProvider('blockedIps')]
    public function test_non_public_and_multicast_addresses_are_blocked(string $ip): void
    {
        $this->assertFalse(PublicHostGuard::isPublicIp($ip), $ip);
    }

    public static function blockedIps(): array
    {
        return array_map(fn ($ip) => [$ip], ['127.0.0.1', '10.1.2.3', '100.64.0.1', '169.254.169.254', '192.0.2.1', '224.0.0.1', '239.255.255.250', '::1', 'fc00::1', 'fe80::1', 'ff02::1', '::ffff:127.0.0.1']);
    }
}
