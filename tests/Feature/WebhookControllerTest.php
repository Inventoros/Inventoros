<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
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

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['manage_organization'],
            ]
        );

        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
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

    public function test_admin_can_view_webhooks_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('webhooks.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_create_webhook_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('webhooks.create'));

        $response->assertStatus(200);
    }

    public function test_admin_can_create_webhook(): void
    {
        $webhookData = [
            'name' => 'New Webhook',
            'url' => 'https://example.com/new-webhook',
            'events' => ['order.created', 'product.updated'],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('webhooks.store'), $webhookData);

        $response->assertRedirect(route('webhooks.index'));

        $this->assertDatabaseHas('webhooks', [
            'name' => 'New Webhook',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_admin_can_view_webhook(): void
    {
        $webhook = $this->createWebhook();

        $response = $this->actingAs($this->admin)
            ->get(route('webhooks.show', $webhook));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_edit_webhook_form(): void
    {
        $webhook = $this->createWebhook();

        $response = $this->actingAs($this->admin)
            ->get(route('webhooks.edit', $webhook));

        $response->assertStatus(200);
    }

    public function test_admin_can_update_webhook(): void
    {
        $webhook = $this->createWebhook();

        $response = $this->actingAs($this->admin)
            ->put(route('webhooks.update', $webhook), [
                'name' => 'Updated Webhook',
                'url' => $webhook->url,
                'events' => $webhook->events,
            ]);

        $response->assertRedirect(route('webhooks.index'));

        $this->assertDatabaseHas('webhooks', [
            'id' => $webhook->id,
            'name' => 'Updated Webhook',
        ]);
    }

    public function test_admin_can_delete_webhook(): void
    {
        $webhook = $this->createWebhook();

        $response = $this->actingAs($this->admin)
            ->delete(route('webhooks.destroy', $webhook));

        $response->assertRedirect(route('webhooks.index'));

        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
    }

    public function test_member_cannot_view_webhooks(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('webhooks.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_webhooks(): void
    {
        $response = $this->get(route('webhooks.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_webhook_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('webhooks.store'), [
                'name' => '',
                'url' => '',
            ]);

        $response->assertSessionHasErrors(['name', 'url']);
    }
}
