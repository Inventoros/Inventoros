<?php

namespace Tests\Feature;

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

class WebhookDeliveryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_signature_is_computed_over_exact_wire_bytes(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $org = Organization::create(['name' => 'WHO', 'email' => 'who@test.com']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);

        $webhook = Webhook::create([
            'organization_id' => $org->id,
            'name' => 'Receiver',
            'url' => 'https://example.com/hook',
            // A secret. Payload below contains slashes and unicode so we can
            // detect re-encoding by the HTTP client.
            'secret' => 's3cr3t-hmac-key',
            'events' => ['product.created'],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $payload = [
            'event' => 'product.created',
            'data' => [
                'url' => 'https://example.com/products/1',
                'name' => "Widget // édition spéciale",
                'tags' => ['a/b', 'c'],
            ],
        ];

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'organization_id' => $org->id,
            'event' => 'product.created',
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        Http::fake([
            'example.com/*' => Http::response('{"ok":true}', 200),
        ]);

        (new WebhookDeliveryJob($delivery))->handle();

        Http::assertSent(function (HttpRequest $request) use ($webhook) {
            // The body the receiver actually receives:
            $wireBody = $request->body();

            $sentSignature = $request->header('X-Webhook-Signature')[0] ?? null;
            $expectedSignature = WebhookService::sign($wireBody, $webhook->secret);

            // Two things must hold for receivers (which hash the raw body)
            // to pass HMAC verification:
            // 1. The body on the wire is non-empty.
            // 2. hash_hmac(sha256, <wireBody>, secret) == X-Webhook-Signature.
            return $wireBody !== ''
                && $sentSignature !== null
                && hash_equals($expectedSignature, $sentSignature);
        });

        $delivery->refresh();
        $this->assertSame('success', $delivery->status);
        $this->assertNull($delivery->next_retry_at, 'successful delivery must clear next_retry_at');
    }

    public function test_failed_delivery_populates_next_retry_at_from_backoff(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        $org = Organization::create(['name' => 'NR', 'email' => 'nr@test.com']);
        $user = User::create([
            'name' => 'A', 'email' => 'a@test.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);

        $webhook = Webhook::create([
            'organization_id' => $org->id,
            'name' => 'Receiver', 'url' => 'https://example.com/hook',
            'secret' => 's', 'events' => ['x'], 'is_active' => true,
            'created_by' => $user->id,
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'organization_id' => $org->id,
            'event' => 'x', 'payload' => [],
            'status' => 'pending', 'attempts' => 0,
        ]);

        Http::fake([
            'example.com/*' => Http::response('boom', 500),
        ]);

        try {
            (new WebhookDeliveryJob($delivery))->handle();
        } catch (\Exception) {
            // expected — job re-throws so Laravel's queue retries
        }

        $delivery->refresh();
        $this->assertNotNull($delivery->next_retry_at, 'failed delivery must populate next_retry_at');

        // First retry is BACKOFF_DELAYS[0] = 60s out.
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($delivery->next_retry_at, false), 5);

        // scopeReadyForRetry should NOT yet return the row (next_retry_at is
        // in the future).
        $this->assertSame(0, WebhookDelivery::readyForRetry()->count());

        // Travel past the backoff window — the row becomes retry-ready.
        $this->travel(120)->seconds();
        $this->assertSame(1, WebhookDelivery::readyForRetry()->count());
    }
}
