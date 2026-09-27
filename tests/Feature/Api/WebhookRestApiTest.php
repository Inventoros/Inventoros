<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * REST parity for webhooks. The signing secret is revealed only in the
 * create and regenerate-secret responses, never on read.
 */
class WebhookRestApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
    }

    private function webhook(Organization $org): Webhook
    {
        return Webhook::create([
            'organization_id' => $org->id,
            'name' => 'Hook',
            'url' => 'https://example.com/hook',
            'events' => ['order.created'],
            'is_active' => true,
        ]);
    }

    public function test_secret_is_revealed_on_create_and_never_on_read(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/webhooks', [
            'name' => 'ERP sync',
            'url' => 'https://erp.example.com/hooks',
            'events' => ['order.created', 'product.created'],
        ])->assertCreated();

        $secret = $response->json('secret');
        $id = $response->json('data.id');
        $this->assertIsString($secret);
        $this->assertSame(64, strlen($secret));
        $this->assertSame($secret, Webhook::find($id)->secret);
        $this->assertArrayNotHasKey('secret', $response->json('data'));

        $this->getJson("/api/v1/webhooks/{$id}")->assertOk()->assertJsonMissingPath('data.secret')->assertJsonMissingPath('secret');
        $index = $this->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString($secret, $index->getContent());
    }

    public function test_regenerate_secret_returns_the_new_secret_once(): void
    {
        $webhook = $this->webhook($this->org);
        $old = $webhook->secret;

        Sanctum::actingAs($this->admin);

        $new = $this->postJson("/api/v1/webhooks/{$webhook->id}/regenerate-secret")
            ->assertOk()
            ->json('secret');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $webhook->fresh()->secret);
    }

    public function test_update_and_delete(): void
    {
        $webhook = $this->webhook($this->org);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/webhooks/{$webhook->id}", [
            'name' => 'Renamed',
            'url' => 'https://example.com/other',
            'events' => ['order.updated'],
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/v1/webhooks/{$webhook->id}")->assertOk();
        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
    }

    public function test_rejects_private_targets_and_unknown_events(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/webhooks', ['name' => 'x', 'url' => 'http://127.0.0.1/hook', 'events' => ['order.created']])
            ->assertStatus(422)->assertJsonValidationErrors(['url']);

        $this->postJson('/api/v1/webhooks', ['name' => 'x', 'url' => 'https://example.com', 'events' => ['nope.event']])
            ->assertStatus(422)->assertJsonValidationErrors(['events.0']);
    }

    public function test_lists_deliveries(): void
    {
        $webhook = $this->webhook($this->org);
        WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => 'order.created',
            'payload' => ['a' => 1],
            'status' => 'success',
            'response_status' => 200,
            'attempts' => 1,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/webhooks/{$webhook->id}/deliveries")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'order.created')
            ->assertJsonPath('data.0.response_status', 200);
    }

    public function test_cross_tenant_webhook_is_not_found(): void
    {
        $foreign = $this->webhook($this->makeOrganization('Other'));
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/webhooks/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/webhooks/{$foreign->id}/regenerate-secret")->assertNotFound();
        $this->getJson("/api/v1/webhooks/{$foreign->id}/deliveries")->assertNotFound();
        $this->deleteJson("/api/v1/webhooks/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('webhooks', ['id' => $foreign->id]);
    }

    public function test_requires_manage_organization(): void
    {
        Sanctum::actingAs($this->makeMember($this->org, ['view_settings']));

        $this->getJson('/api/v1/webhooks')->assertForbidden();
        $this->postJson('/api/v1/webhooks', ['name' => 'x', 'url' => 'https://e.com', 'events' => ['order.created']])->assertForbidden();
    }
}
