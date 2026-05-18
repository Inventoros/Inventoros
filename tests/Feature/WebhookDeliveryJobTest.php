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
    }
}
