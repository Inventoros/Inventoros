<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Send test" on one webhook must reach that webhook only. A signed test
 * event delivered to the organization's other endpoints looks genuine to
 * them.
 */
class WebhookTestDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        Queue::fake();

        $this->org = Organization::create(['name' => 'Acme', 'email' => 'acme@org.test']);
        $this->admin = $this->adminOf($this->org, 'admin@acme.test');
    }

    private function adminOf(Organization $org, string $email): User
    {
        $user = User::create([
            'name' => 'Admin', 'email' => $email, 'password' => bcrypt('password'),
            'organization_id' => $org->id,
        ]);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    /** @param array<int, string> $events */
    private function webhook(Organization $org, string $name, array $events, bool $active = true): Webhook
    {
        return Webhook::create([
            'organization_id' => $org->id,
            'name' => $name,
            'url' => 'https://'.strtolower($name).'.example.com/hook',
            'secret' => 'secret-'.$name,
            'events' => $events,
            'is_active' => $active,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_the_test_delivery_goes_only_to_the_selected_webhook(): void
    {
        $selected = $this->webhook($this->org, 'Selected', ['order.created']);
        $other = $this->webhook($this->org, 'Fulfilment', ['order.created']);

        $this->actingAs($this->admin)
            ->post(route('webhooks.test', $selected))
            ->assertRedirect(route('webhooks.show', $selected));

        $deliveries = WebhookDelivery::all();
        $this->assertCount(1, $deliveries);
        $this->assertSame($selected->id, $deliveries[0]->webhook_id);
        $this->assertSame('order.created', $deliveries[0]->event);
        $this->assertTrue($deliveries[0]->payload['data']['test']);
        $this->assertSame(0, WebhookDelivery::where('webhook_id', $other->id)->count());

        Queue::assertPushed(WebhookDeliveryJob::class, 1);
    }

    public function test_other_organizations_webhooks_never_receive_the_test(): void
    {
        $selected = $this->webhook($this->org, 'Selected', ['order.created']);
        $globex = Organization::create(['name' => 'Globex', 'email' => 'globex@org.test']);
        $foreign = $this->webhook($globex, 'Foreign', ['order.created']);

        $this->actingAs($this->admin)->post(route('webhooks.test', $selected));

        $this->assertSame(0, WebhookDelivery::where('webhook_id', $foreign->id)->count());
    }

    public function test_a_tenant_cannot_test_another_organizations_webhook(): void
    {
        $globex = Organization::create(['name' => 'Globex', 'email' => 'globex@org.test']);
        $foreign = $this->webhook($globex, 'Foreign', ['order.created']);

        $this->actingAs($this->admin)
            ->post(route('webhooks.test', $foreign))
            ->assertForbidden();

        $this->assertSame(0, WebhookDelivery::count());
        Queue::assertNothingPushed();
    }
}
