<?php

namespace Tests\Feature\Security;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Outbound webhooks carry a timestamp and a second signature over
 * "{timestamp}.{body}", so receivers can refuse replayed deliveries. The
 * original body-only signature is still sent unchanged for existing
 * receivers.
 */
final class WebhookTimestampedSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Webhook $webhook;

    /** @var array<int, callable> */
    private array $filters = [];

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->organization = Organization::create(['name' => 'Signed', 'email' => 'signed@test.com']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'signed-admin@test.com', 'password' => bcrypt('x'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);

        $this->webhook = Webhook::create([
            'organization_id' => $this->organization->id,
            'name' => 'Receiver',
            'url' => 'https://93.184.215.14/hook',
            'events' => ['product.created'],
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as $callback) {
            remove_filter('webhook_delivery_request', $callback);
        }

        parent::tearDown();
    }

    private function delivery(): WebhookDelivery
    {
        return WebhookDelivery::create([
            'webhook_id' => $this->webhook->id,
            'organization_id' => $this->organization->id,
            'event' => 'product.created',
            'payload' => ['event' => 'product.created', 'data' => ['sku' => 'A-1', 'url' => 'https://x.test/a/b']],
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function test_a_delivery_sends_a_timestamp_and_a_timestamped_signature_alongside_the_original_one(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->freezeTime();

        (new WebhookDeliveryJob($this->delivery()))->handle();

        Http::assertSent(function (HttpRequest $request) {
            $body = $request->body();
            $timestamp = $request->header('X-Webhook-Timestamp')[0] ?? null;
            $v2 = $request->header('X-Webhook-Signature-V2')[0] ?? null;

            return $body !== ''
                // The original header keeps its exact value: HMAC of the body.
                && ($request->header('X-Webhook-Signature')[0] ?? null) === hash_hmac('sha256', $body, $this->webhook->secret)
                && $timestamp === (string) now()->getTimestamp()
                && $v2 === hash_hmac('sha256', $timestamp.'.'.$body, $this->webhook->secret)
                && WebhookService::verifyTimestampedSignature($body, $this->webhook->secret, $v2, $timestamp);
        });
    }

    public function test_each_retry_is_signed_with_a_fresh_timestamp(): void
    {
        Http::fake(['*' => Http::sequence()->push('boom', 500)->push('ok', 200)]);
        $delivery = $this->delivery();

        $this->travelTo(now()->setTimestamp(1_800_000_000));
        try {
            (new WebhookDeliveryJob($delivery))->handle();
        } catch (\Exception) {
            // The failed attempt is rethrown so the queue retries it.
        }

        $this->travelTo(now()->setTimestamp(1_800_000_090));
        (new WebhookDeliveryJob($delivery->fresh()))->handle();

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0])->values();
        $this->assertCount(2, $sent);

        $this->assertSame('1800000000', $sent[0]->header('X-Webhook-Timestamp')[0]);
        $this->assertSame('1800000090', $sent[1]->header('X-Webhook-Timestamp')[0]);
        $this->assertNotSame($sent[0]->header('X-Webhook-Signature-V2')[0], $sent[1]->header('X-Webhook-Signature-V2')[0]);

        foreach ($sent as $request) {
            $this->assertSame(
                WebhookService::signWithTimestamp($request->body(), $this->webhook->secret, (int) $request->header('X-Webhook-Timestamp')[0]),
                $request->header('X-Webhook-Signature-V2')[0],
            );
            // The original signature is identical on every attempt.
            $this->assertSame(WebhookService::sign($request->body(), $this->webhook->secret), $request->header('X-Webhook-Signature')[0]);
        }
    }

    public function test_a_plugin_cannot_set_the_timestamp_or_timestamped_signature_headers(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $filter = function (array $request) {
            $request['headers'] = [
                'X-Webhook-Timestamp' => '1',
                'x-webhook-signature-v2' => 'forged',
                'X-Fine' => 'kept',
            ];

            return $request;
        };
        add_filter('webhook_delivery_request', $filter);
        $this->filters[] = $filter;

        (new WebhookDeliveryJob($this->delivery()))->handle();

        Http::assertSent(function (HttpRequest $request) {
            return count($request->header('X-Webhook-Timestamp')) === 1
                && $request->header('X-Webhook-Timestamp')[0] !== '1'
                && count($request->header('X-Webhook-Signature-V2')) === 1
                && $request->header('X-Webhook-Signature-V2')[0] !== 'forged'
                && $request->header('X-Fine')[0] === 'kept';
        });
    }

    public function test_verification_refuses_a_stale_timestamp_or_a_tampered_body(): void
    {
        $body = '{"event":"product.created"}';
        $now = 1_800_000_000;
        $signature = WebhookService::signWithTimestamp($body, 'k', $now);

        $this->assertTrue(WebhookService::verifyTimestampedSignature($body, 'k', $signature, (string) $now, 300, $now + 299));
        $this->assertFalse(WebhookService::verifyTimestampedSignature($body, 'k', $signature, (string) $now, 300, $now + 301));
        $this->assertFalse(WebhookService::verifyTimestampedSignature($body, 'k', $signature, (string) $now, 300, $now - 301));
        $this->assertFalse(WebhookService::verifyTimestampedSignature($body.' ', 'k', $signature, (string) $now, 300, $now));
        $this->assertFalse(WebhookService::verifyTimestampedSignature($body, 'k', $signature, (string) ($now + 1), 300, $now));
        $this->assertFalse(WebhookService::verifyTimestampedSignature($body, 'k', $signature, 'not-a-number', 300, $now));
        // The body-only signature is not accepted as a timestamped one.
        $this->assertFalse(WebhookService::verifyTimestampedSignature($body, 'k', WebhookService::sign($body, 'k'), (string) $now, 300, $now));
    }
}
