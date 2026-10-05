<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Plugins\PluginWebhookEventRegistry;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * register_webhook_event() / dispatch_webhook_event(): plugins add outbound
 * webhook events that appear in the event picker and are delivered by core
 * (signed, retried, SSRF-checked), to subscribed webhooks of the right
 * organization only, after commit.
 */
final class PluginWebhookEventsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Hooks', 'email' => 'h@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@wh.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        register_webhook_event('cycle-counts.session_completed', 'When a count session is completed', 'Cycle counts');
    }

    protected function tearDown(): void
    {
        app(PluginWebhookEventRegistry::class)->clear();

        parent::tearDown();
    }

    private function webhook(array $events, ?Organization $org = null): Webhook
    {
        $org ??= $this->org;

        return Webhook::create([
            'organization_id' => $org->id, 'name' => 'Hook', 'url' => 'https://example.com/hook',
            'secret' => 'shh', 'events' => $events, 'is_active' => true, 'created_by' => $this->admin->id,
        ]);
    }

    public function test_a_registered_event_is_offered_in_the_picker_after_the_core_events(): void
    {
        $this->assertContains('cycle-counts.session_completed', WebhookService::availableEvents());
        $this->assertSame('product.created', WebhookService::availableEvents()[0]);
        $this->assertSame(
            ['cycle-counts.session_completed' => 'When a count session is completed'],
            WebhookService::eventGroups()['Cycle counts'],
        );

        $this->actingAs($this->admin)->get(route('webhooks.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('eventGroups', fn ($groups) => ($groups['Cycle counts']['cycle-counts.session_completed'] ?? null) === 'When a count session is completed')
                ->etc());
    }

    public function test_the_group_defaults_to_the_plugin_name(): void
    {
        register_webhook_event('stock-insights.digest_ready', 'When the weekly digest is ready');

        $this->assertArrayHasKey('stock-insights.digest_ready', WebhookService::eventGroups()['Stock Insights']);
    }

    public function test_a_webhook_can_subscribe_to_a_plugin_event_but_not_to_an_unknown_one(): void
    {
        $this->actingAs($this->admin)->post(route('webhooks.store'), [
            'name' => 'Counts', 'url' => 'https://example.com/hook',
            'events' => ['cycle-counts.session_completed', 'product.created'], 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['cycle-counts.session_completed', 'product.created'], Webhook::sole()->events);

        $this->actingAs($this->admin)->post(route('webhooks.store'), [
            'name' => 'Bad', 'url' => 'https://example.com/hook', 'events' => ['cycle-counts.unknown'], 'is_active' => true,
        ])->assertSessionHasErrors('events.0');
    }

    public function test_dispatch_reaches_only_subscribed_webhooks_of_that_organization(): void
    {
        $subscribed = $this->webhook(['cycle-counts.session_completed']);
        $this->webhook(['product.created']);
        $other = Organization::create(['name' => 'Other', 'email' => 'o@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->webhook(['cycle-counts.session_completed'], $other);
        Queue::fake();

        dispatch_webhook_event('cycle-counts.session_completed', ['session_id' => 7], $this->org);

        Queue::assertPushed(WebhookDeliveryJob::class, 1);
        $delivery = WebhookDelivery::sole();
        $this->assertSame($subscribed->id, $delivery->webhook_id);
        $this->assertSame('cycle-counts.session_completed', $delivery->payload['event']);
        $this->assertSame(['session_id' => 7], $delivery->payload['data']);
        $this->assertSame($this->org->id, $delivery->payload['organization_id']);
    }

    public function test_the_delivery_is_signed_like_a_core_event(): void
    {
        $webhook = $this->webhook(['cycle-counts.session_completed']);
        Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

        dispatch_webhook_event('cycle-counts.session_completed', ['session_id' => 7], $this->org->id);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://example.com/hook'
            && hash_equals(WebhookService::sign($request->body(), $webhook->secret), $request->header('X-Webhook-Signature')[0] ?? ''));
        $this->assertSame('success', WebhookDelivery::sole()->status);
    }

    public function test_nothing_is_sent_when_the_transaction_rolls_back(): void
    {
        $this->webhook(['cycle-counts.session_completed']);
        Queue::fake();

        try {
            DB::transaction(function () {
                dispatch_webhook_event('cycle-counts.session_completed', ['session_id' => 7], $this->org);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_an_unregistered_event_cannot_be_dispatched(): void
    {
        $this->expectException(InvalidArgumentException::class);

        dispatch_webhook_event('cycle-counts.not_registered', [], $this->org);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNames(): array
    {
        return [
            'no dot' => ['cyclecounts'],
            'core prefix' => ['product.synced'],
            'another core prefix' => ['purchase_order.synced'],
            'upper case' => ['Cycle-Counts.done'],
            'empty event' => ['cycle-counts.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNames')]
    public function test_invalid_event_names_are_refused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        register_webhook_event($name, 'Something');
    }
}
