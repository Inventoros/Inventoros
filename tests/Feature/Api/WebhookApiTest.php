<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    public function test_can_list_webhooks(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createWebhook();

        $response = $this->getJson('/api/v1/webhooks');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_can_create_webhook(): void
    {
        Sanctum::actingAs($this->admin);

        $webhookData = [
            'name' => 'New Webhook',
            'url' => 'https://example.com/new-webhook',
            'events' => ['order.created', 'product.updated'],
        ];

        $response = $this->postJson('/api/v1/webhooks', $webhookData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('webhooks', [
            'name' => 'New Webhook',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_can_view_webhook(): void
    {
        Sanctum::actingAs($this->admin);

        $webhook = $this->createWebhook();

        $response = $this->getJson("/api/v1/webhooks/{$webhook->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $webhook->id);
    }

    public function test_can_update_webhook(): void
    {
        Sanctum::actingAs($this->admin);

        $webhook = $this->createWebhook();

        $response = $this->putJson("/api/v1/webhooks/{$webhook->id}", [
            'name' => 'Updated Webhook',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('webhooks', [
            'id' => $webhook->id,
            'name' => 'Updated Webhook',
        ]);
    }

    public function test_can_delete_webhook(): void
    {
        Sanctum::actingAs($this->admin);

        $webhook = $this->createWebhook();

        $response = $this->deleteJson("/api/v1/webhooks/{$webhook->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
    }

    public function test_create_webhook_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/webhooks', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'url']);
    }

    public function test_unauthenticated_cannot_list_webhooks(): void
    {
        $response = $this->getJson('/api/v1/webhooks');

        $response->assertStatus(401);
    }
}
