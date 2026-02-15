<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Job for delivering webhook payloads to external URLs.
 */
final class WebhookDeliveryJob implements ShouldQueue
{
    public const MAX_TRIES = 5;
    public const TIMEOUT_SECONDS = 30;
    public const RESPONSE_BODY_LIMIT = 5000;

    private const BACKOFF_DELAYS = [60, 300, 1800, 7200, 86400];

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The backoff times between retries in seconds.
     * 1 minute, 5 minutes, 30 minutes, 2 hours, 24 hours
     *
     * @var array<int>
     */
    public $backoff = [60, 300, 1800, 7200, 86400];

    /**
     * Create a new job instance.
     *
     * @param WebhookDelivery $delivery The webhook delivery to process
     */
    public function __construct(
        public WebhookDelivery $delivery
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \Exception When the webhook delivery fails
     */
    public function handle(): void
    {
        $webhook = $this->delivery->webhook;

        // Check if webhook still exists and is active
        if (!$webhook || !$webhook->is_active) {
            $this->delivery->update(['status' => 'failed']);
            Log::info('Webhook delivery cancelled: webhook inactive or deleted', [
                'delivery_id' => $this->delivery->id,
            ]);
            return;
        }

        $payloadJson = json_encode($this->delivery->payload);
        $signature = WebhookService::sign($payloadJson, $webhook->secret);

        $this->delivery->increment('attempts');

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => $this->delivery->event,
                    'X-Webhook-Delivery' => (string) $this->delivery->id,
                ])
                ->post($webhook->url, $this->delivery->payload);

            $this->delivery->update([
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 5000),
                'status' => $response->successful() ? 'success' : 'pending',
                'completed_at' => $response->successful() ? now() : null,
            ]);

            if ($response->successful()) {
                Log::info('Webhook delivered successfully', [
                    'delivery_id' => $this->delivery->id,
                    'webhook_id' => $webhook->id,
                    'event' => $this->delivery->event,
                    'status' => $response->status(),
                ]);
            } else {
                Log::warning('Webhook delivery failed with HTTP error', [
                    'delivery_id' => $this->delivery->id,
                    'webhook_id' => $webhook->id,
                    'event' => $this->delivery->event,
                    'status' => $response->status(),
                    'attempts' => $this->delivery->attempts,
                ]);
                throw new \Exception("Webhook returned {$response->status()}");
            }
        } catch (\Exception $e) {
            $this->delivery->update([
                'response_body' => Str::limit($e->getMessage(), 5000),
            ]);

            Log::warning('Webhook delivery exception', [
                'delivery_id' => $this->delivery->id,
                'webhook_id' => $webhook->id,
                'error' => $e->getMessage(),
                'attempts' => $this->delivery->attempts,
            ]);

            throw $e; // Let Laravel handle retry
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable $e The exception that caused the failure
     * @return void
     */
    public function failed(Throwable $e): void
    {
        $this->delivery->update([
            'status' => 'failed',
            'response_body' => Str::limit($e->getMessage(), 5000),
        ]);

        Log::error('Webhook delivery permanently failed', [
            'delivery_id' => $this->delivery->id,
            'webhook_id' => $this->delivery->webhook_id,
            'event' => $this->delivery->event,
            'error' => $e->getMessage(),
            'attempts' => $this->delivery->attempts,
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'webhook',
            'webhook:' . $this->delivery->webhook_id,
            'event:' . $this->delivery->event,
        ];
    }
}
