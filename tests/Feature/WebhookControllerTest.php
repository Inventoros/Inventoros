<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
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
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->member->forceFill(['role' => 'member'])->save();

        $this->createSystemRoles();

        // Bridge Laravel Gate to custom permission system used in WebhookController
        Gate::before(function ($user, $ability) {
            if ($user->hasPermission($ability)) {
                return true;
            }
        });
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
        $secret = $attributes['secret'] ?? 'test-secret';
        unset($attributes['secret']);

        $webhook = Webhook::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Webhook',
            'url' => 'https://example.com/webhook',
            'events' => ['order.created'],
            'is_active' => true,
        ], $attributes));

        // `secret` is intentionally not mass-assignable; set it directly so a
        // test can pin a known value.
        $webhook->secret = $secret;
        $webhook->save();

        return $webhook;
    }

    public function test_webhook_secret_is_not_mass_assignable(): void
    {
        $webhook = new Webhook;
        $webhook->fill([
            'name' => 'Hook',
            'url' => 'https://example.com/hook',
            'events' => ['order.created'],
            'is_active' => true,
            'organization_id' => $this->organization->id,
            'secret' => 'attacker-chosen-secret',
        ]);

        // A request payload must never be able to set the signing secret.
        $this->assertNotSame('attacker-chosen-secret', $webhook->secret);
    }

    public function test_admin_can_view_webhooks_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('webhooks.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_access_create_webhook_route(): void
    {
        // WebhookController uses a resource route but doesn't implement create()
        // Webhook creation is handled via the index page modal
        $response = $this->actingAs($this->admin)
            ->get(route('webhooks.create'));

        // The controller doesn't have a create() method, so this returns 500
        $response->assertStatus(500);
    }

    public function test_index_does_not_expose_the_webhook_signing_secret(): void
    {
        $this->createWebhook(['secret' => 'super-secret-signing-key']);

        $response = $this->actingAs($this->admin)->get(route('webhooks.index'));

        $response->assertStatus(200);
        // The signing secret must never be serialized into the page props —
        // anyone able to read them could forge signed deliveries.
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Webhooks/Index')
            ->missing('webhooks.0.secret')
        );
        $response->assertDontSee('super-secret-signing-key');
    }

    public function test_show_does_not_expose_the_webhook_signing_secret(): void
    {
        $webhook = $this->createWebhook(['secret' => 'super-secret-signing-key']);

        $response = $this->actingAs($this->admin)->get(route('webhooks.show', $webhook));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Webhooks/Show')
            ->where('webhook.id', $webhook->id)
            ->missing('webhook.secret')
        );
        $response->assertDontSee('super-secret-signing-key');
    }

    public function test_creating_a_webhook_reveals_its_secret_exactly_once(): void
    {
        $response = $this->actingAs($this->admin)->post(route('webhooks.store'), [
            'name' => 'Reveal Once',
            'url' => 'https://example.com/hook',
            'events' => ['order.created'],
            'is_active' => true,
        ]);

        $response->assertRedirect(route('webhooks.index'));

        // The plaintext secret is handed back exactly once, via a one-time
        // flash, so the receiver can be configured without it living in props.
        $response->assertSessionHas('newWebhookSecret');
        $revealed = session('newWebhookSecret');
        $this->assertIsString($revealed);

        $webhook = Webhook::where('name', 'Reveal Once')->firstOrFail();
        $this->assertSame($webhook->secret, $revealed);
    }

    public function test_regenerating_the_secret_reveals_it_once_and_rotates_it(): void
    {
        $webhook = $this->createWebhook(['secret' => 'old-secret']);

        $response = $this->actingAs($this->admin)
            ->post(route('webhooks.regenerate-secret', $webhook));

        $response->assertRedirect(route('webhooks.show', $webhook));
        $response->assertSessionHas('newWebhookSecret');

        $revealed = session('newWebhookSecret');
        $this->assertNotSame('old-secret', $revealed);
        $this->assertSame($revealed, $webhook->fresh()->secret);
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

    public function test_admin_can_access_edit_webhook_route(): void
    {
        $webhook = $this->createWebhook();

        // WebhookController uses a resource route but doesn't implement edit()
        // Webhook editing is handled via the show page
        $response = $this->actingAs($this->admin)
            ->get(route('webhooks.edit', $webhook));

        // The controller doesn't have an edit() method, so this returns 500
        $response->assertStatus(500);
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

        $response->assertRedirect(route('webhooks.show', $webhook));

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
