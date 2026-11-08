<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\PublicHostResolver;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

final class WebhookResponseLimitsTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private function delivery(): WebhookDelivery
    {
        $org = $this->makeOrganization();
        $staff = $this->makeAdmin($org);
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')
            ->once()->with('receiver.example.test')->andReturn(['8.8.8.8']);
        $webhook = Webhook::create([
            'organization_id' => $org->id,
            'name' => 'Bounded receiver',
            'url' => 'https://receiver.example.test/hook',
            'secret' => 'response-limit-test',
            'events' => ['product.created'],
            'is_active' => true,
            'created_by' => $staff->id,
        ]);

        return WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'organization_id' => $org->id,
            'event' => 'product.created',
            'payload' => ['name' => 'Widget'],
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function test_only_a_bounded_prefix_is_read_for_the_delivery_log(): void
    {
        $delivery = $this->delivery();
        $read = 0;
        // This response has no known length and refuses reads beyond the log
        // prefix. Stringifying it would continue pumping and fail the test.
        $stream = new PumpStream(function (int $length) use (&$read): string {
            $read += $length;
            $this->assertLessThanOrEqual(WebhookDeliveryJob::RESPONSE_BODY_LIMIT, $read);

            return str_repeat('x', $length);
        });
        $pending = Mockery::mock(PendingRequest::class);
        $pending->shouldReceive('setHandler', 'timeout', 'withHeaders', 'withBody')->andReturnSelf();
        $pending->shouldReceive('post')->once()->andReturn(new ClientResponse(new Response(200, [], $stream)));
        Http::shouldReceive('withOptions')->once()->andReturn($pending);

        (new WebhookDeliveryJob($delivery))->handle();

        $this->assertSame(WebhookDeliveryJob::RESPONSE_BODY_LIMIT, $read);
        $this->assertSame('success', $delivery->fresh()->status);
        $this->assertSame(str_repeat('x', WebhookDeliveryJob::RESPONSE_BODY_LIMIT), $delivery->fresh()->response_body);
    }

    #[DataProvider('responseEncodings')]
    public function test_the_transport_sink_rejects_oversized_decoded_chunks_without_content_length(array $headers): void
    {
        $delivery = $this->delivery();
        Http::preventStrayRequests();
        Http::fake(function ($request, array $options) use ($headers) {
            // Emulate cURL delivering decoded chunks into the configured sink,
            // including when compressed wire bytes or chunking hide the size.
            $sink = $options['sink'];
            $chunk = str_repeat('a', 8192);
            for ($bytes = 0; $bytes < WebhookDeliveryJob::MAX_RESPONSE_BYTES; $bytes += strlen($chunk)) {
                fwrite($sink, $chunk);
            }
            $this->assertSame(WebhookDeliveryJob::MAX_RESPONSE_BYTES, fstat($sink)['size']);
            try {
                fwrite($sink, 'overflow');
            } finally {
                $this->assertSame(WebhookDeliveryJob::MAX_RESPONSE_BYTES, fstat($sink)['size']);
            }

            return Http::response('', 200, $headers);
        });

        try {
            (new WebhookDeliveryJob($delivery))->handle();
            $this->fail('An oversized response must stop the request.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Webhook response exceeds the 1 MiB limit', $error->getMessage());
        }

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->next_retry_at);
        $this->assertStringContainsString('Webhook response exceeds', $delivery->response_body);
    }

    public static function responseEncodings(): array
    {
        return [
            'chunked' => [['Transfer-Encoding' => 'chunked']],
            'compressed' => [['Content-Encoding' => 'gzip']],
        ];
    }
}
