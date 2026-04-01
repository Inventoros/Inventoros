<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['manage_organization'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    protected function createWebhook(array $attributes = []): Webhook
    {
        return Webhook::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Webhook',
            'url' => 'https://example.com/webhook',
            'secret' => 'test-secret',
            'events' => ['order.created'],
            'is_active' => true,
        ], $attributes));
    }

    public function test_webhook_model_can_be_created(): void
    {
        $webhook = $this->createWebhook();

        $this->assertDatabaseHas('webhooks', [
            'id' => $webhook->id,
            'name' => 'Test Webhook',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_webhook_model_can_be_updated(): void
    {
        $webhook = $this->createWebhook();

        $webhook->update(['name' => 'Updated Webhook']);

        $this->assertDatabaseHas('webhooks', [
            'id' => $webhook->id,
            'name' => 'Updated Webhook',
        ]);
    }

    public function test_webhook_model_can_be_deleted(): void
    {
        $webhook = $this->createWebhook();
        $webhookId = $webhook->id;

        $webhook->delete();

        $this->assertDatabaseMissing('webhooks', ['id' => $webhookId]);
    }

    public function test_webhook_requires_name(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Webhook::create([
            'organization_id' => $this->organization->id,
            'url' => 'https://example.com/webhook',
            'events' => ['order.created'],
        ]);
    }

    public function test_webhook_belongs_to_organization(): void
    {
        $webhook = $this->createWebhook();

        $this->assertEquals($this->organization->id, $webhook->organization_id);
    }

    public function test_webhook_stores_events_as_array(): void
    {
        $webhook = $this->createWebhook(['events' => ['order.created', 'product.updated']]);

        $this->assertIsArray($webhook->events);
        $this->assertCount(2, $webhook->events);
        $this->assertContains('order.created', $webhook->events);
        $this->assertContains('product.updated', $webhook->events);
    }

    public function test_unauthenticated_cannot_access_webhooks(): void
    {
        $response = $this->get('/webhooks');

        $response->assertRedirect('/login');
    }
}
