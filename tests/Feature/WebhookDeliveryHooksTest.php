<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\HookRegistry;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The outbound webhook delivery hooks: webhook_should_deliver,
 * webhook_delivery_request, webhook_delivery_retry_policy and
 * webhook_delivery_attempted. Plugins use them to filter, shape and observe
 * deliveries; core keeps the URL, the signature and the SSRF checks.
 */
final class WebhookDeliveryHooksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Webhook $webhook;

    /** @var array<int, array{0: string, 1: callable, 2: string}> */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->organization = Organization::create(['name' => 'Hooks', 'email' => 'hooks@test.com']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'hooks-admin@test.com', 'password' => bcrypt('x'),
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
        foreach ($this->registered as [$tag, $callback, $kind]) {
            $kind === 'action' ? remove_action($tag, $callback) : remove_filter($tag, $callback);
        }

        parent::tearDown();
    }

    private function hook(string $tag, callable $callback): void
    {
        add_filter($tag, $callback);
        $this->registered[] = [$tag, $callback, 'filter'];
    }

    private function action(string $tag, callable $callback): void
    {
        add_action($tag, $callback);
        $this->registered[] = [$tag, $callback, 'action'];
    }

    private function delivery(array $payload = ['event' => 'product.created', 'data' => ['sku' => 'A-1']]): WebhookDelivery
    {
        return WebhookDelivery::create([
            'webhook_id' => $this->webhook->id,
            'organization_id' => $this->organization->id,
            'event' => 'product.created',
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function test_the_hooks_are_listed_in_the_registry(): void
    {
        $this->assertArrayHasKey('webhook_should_deliver', HookRegistry::getFilters());
        $this->assertArrayHasKey('webhook_delivery_request', HookRegistry::getFilters());
        $this->assertArrayHasKey('webhook_delivery_retry_policy', HookRegistry::getFilters());
        $this->assertArrayHasKey('webhook_delivery_attempted', HookRegistry::getActions());
    }

    public function test_a_plugin_can_skip_a_delivery_before_it_is_logged(): void
    {
        Queue::fake();
        $seen = [];
        $this->hook('webhook_should_deliver', function (bool $deliver, Webhook $webhook, string $event, array $payload) use (&$seen) {
            $seen[] = [$webhook->id, $event, $payload['data']['sku']];

            return $payload['data']['sku'] !== 'SKIP';
        });

        WebhookService::dispatch('product.created', ['sku' => 'SKIP'], $this->organization->id);
        WebhookService::dispatch('product.created', ['sku' => 'SEND'], $this->organization->id);

        $this->assertSame([[$this->webhook->id, 'product.created', 'SKIP'], [$this->webhook->id, 'product.created', 'SEND']], $seen);
        $this->assertSame(['SEND'], WebhookDelivery::all()->map(fn ($d) => $d->payload['data']['sku'])->all());
        Queue::assertPushed(WebhookDeliveryJob::class, 1);
    }

    public function test_a_throwing_should_deliver_filter_still_delivers(): void
    {
        Queue::fake();
        $this->hook('webhook_should_deliver', fn () => throw new RuntimeException('plugin bug'));

        WebhookService::dispatch('product.created', ['sku' => 'A'], $this->organization->id);

        $this->assertSame(1, WebhookDelivery::count());
        Queue::assertPushed(WebhookDeliveryJob::class, 1);
    }

    public function test_without_listeners_the_request_is_unchanged(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $delivery = $this->delivery();

        (new WebhookDeliveryJob($delivery))->handle();

        Http::assertSent(function (HttpRequest $request) use ($delivery) {
            return $request->body() === json_encode($delivery->payload, JSON_UNESCAPED_SLASHES)
                && $request->header('X-Webhook-Signature')[0] === WebhookService::sign($request->body(), $this->webhook->secret)
                && $request->url() === 'https://93.184.215.14/hook';
        });
        $this->assertSame('success', $delivery->fresh()->status);
    }

    public function test_a_plugin_can_reshape_the_body_and_add_headers_and_core_signs_the_final_bytes(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->hook('webhook_delivery_request', function (array $request, WebhookDelivery $delivery, Webhook $webhook) {
            $request['body'] = json_encode(['sku' => $delivery->payload['data']['sku']]);
            $request['headers']['Authorization'] = 'Bearer abc';
            $request['timeout'] = 7;

            return $request;
        });

        (new WebhookDeliveryJob($this->delivery()))->handle();

        Http::assertSent(function (HttpRequest $request) {
            return $request->body() === '{"sku":"A-1"}'
                && $request->header('Authorization')[0] === 'Bearer abc'
                && $request->header('X-Webhook-Signature')[0] === WebhookService::sign('{"sku":"A-1"}', $this->webhook->secret);
        });
    }

    public function test_a_plugin_cannot_override_core_headers_or_inject_header_lines(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->hook('webhook_delivery_request', function (array $request) {
            $request['headers'] = [
                'X-Webhook-Signature' => 'forged',
                'x-webhook-event' => 'forged',
                'Host' => 'internal.local',
                'Content-Type' => 'text/plain',
                'X-Split' => "a\r\nX-Injected: 1",
                'Bad Name' => 'x',
                'X-Fine' => 'kept',
            ];
            $request['timeout'] = 5000;

            return $request;
        });

        (new WebhookDeliveryJob($this->delivery()))->handle();

        Http::assertSent(function (HttpRequest $request) {
            return $request->header('X-Webhook-Signature')[0] === WebhookService::sign($request->body(), $this->webhook->secret)
                && $request->header('X-Webhook-Event')[0] === 'product.created'
                && $request->header('Host') !== ['internal.local']
                && $request->header('Content-Type')[0] === 'application/json'
                && $request->header('X-Split') === []
                && $request->header('X-Injected') === []
                && $request->header('X-Fine')[0] === 'kept';
        });
        $this->assertSame(WebhookDeliveryJob::TIMEOUT_SECONDS, WebhookDeliveryJob::clampTimeout(5000));
        $this->assertSame(1, WebhookDeliveryJob::clampTimeout(0));
    }

    public function test_a_non_string_body_from_a_filter_is_ignored(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->hook('webhook_delivery_request', fn (array $request) => ['body' => ['not' => 'a string']] + $request);
        $delivery = $this->delivery();

        (new WebhookDeliveryJob($delivery))->handle();

        Http::assertSent(fn (HttpRequest $request) => $request->body() === json_encode($delivery->payload, JSON_UNESCAPED_SLASHES));
    }

    public function test_the_request_filter_cannot_change_the_destination_and_ssrf_still_applies(): void
    {
        Http::fake();
        $this->webhook->update(['url' => 'http://127.0.0.1/hook']);
        $this->hook('webhook_delivery_request', fn (array $request) => $request + ['url' => 'https://93.184.215.14/elsewhere']);
        $attempts = [];
        $this->action('webhook_delivery_attempted', function ($delivery, $webhook, array $result) use (&$attempts) {
            $attempts[] = $result;
        });

        try {
            (new WebhookDeliveryJob($this->delivery()))->handle();
            $this->fail('A loopback destination must be refused.');
        } catch (RuntimeException) {
        }

        Http::assertNothingSent();
        $this->assertCount(1, $attempts);
        $this->assertFalse($attempts[0]['successful']);
        $this->assertNull($attempts[0]['status']);
        $this->assertStringContainsString('non-public', $attempts[0]['error']);
    }

    public function test_attempts_are_reported_once_each_with_status_and_duration(): void
    {
        Http::fakeSequence()->push('nope', 500)->push('ok', 200);
        $attempts = [];
        $this->action('webhook_delivery_attempted', function (WebhookDelivery $delivery, Webhook $webhook, array $result) use (&$attempts) {
            $attempts[] = $result;
        });
        $delivery = $this->delivery();

        try {
            (new WebhookDeliveryJob($delivery))->handle();
        } catch (\Exception) {
        }
        (new WebhookDeliveryJob($delivery->fresh()))->handle();

        $this->assertCount(2, $attempts);
        $this->assertSame([false, 500, 1, true], [$attempts[0]['successful'], $attempts[0]['status'], $attempts[0]['attempt'], $attempts[0]['will_retry']]);
        $this->assertSame([true, 200, 2, false], [$attempts[1]['successful'], $attempts[1]['status'], $attempts[1]['attempt'], $attempts[1]['will_retry']]);
        $this->assertIsInt($attempts[1]['duration_ms']);
        $this->assertNull($attempts[1]['error']);
    }

    public function test_a_throwing_attempted_listener_does_not_break_delivery(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->action('webhook_delivery_attempted', fn () => throw new RuntimeException('plugin bug'));
        $delivery = $this->delivery();

        (new WebhookDeliveryJob($delivery))->handle();

        $this->assertSame('success', $delivery->fresh()->status);
    }

    public function test_a_plugin_can_set_the_retry_policy_within_bounds(): void
    {
        $this->hook('webhook_delivery_retry_policy', fn (array $policy, WebhookDelivery $delivery, Webhook $webhook) => ['tries' => 3, 'backoff' => [10, 20]]);
        $job = new WebhookDeliveryJob($this->delivery());
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 20], $job->backoff);

        remove_filter('webhook_delivery_retry_policy', end($this->registered)[1]);
        $this->hook('webhook_delivery_retry_policy', fn () => ['tries' => 500, 'backoff' => [0, 999999, 'x']]);
        $job = new WebhookDeliveryJob($this->delivery());
        $this->assertSame(WebhookDeliveryJob::MAX_POLICY_TRIES, $job->tries);
        $this->assertSame([1, 86400], $job->backoff);
    }

    public function test_the_default_retry_policy_is_unchanged(): void
    {
        $job = new WebhookDeliveryJob($this->delivery());

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 1800, 7200, 86400], $job->backoff);
    }

    public function test_the_policy_backoff_drives_next_retry_at(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);
        $this->hook('webhook_delivery_retry_policy', fn () => ['tries' => 2, 'backoff' => [45]]);
        $delivery = $this->delivery();
        $this->travelTo(now()->startOfMinute());

        try {
            (new WebhookDeliveryJob($delivery))->handle();
        } catch (\Exception) {
        }

        $this->assertEquals(now()->addSeconds(45)->toDateTimeString(), $delivery->fresh()->next_retry_at->toDateTimeString());
    }
}
