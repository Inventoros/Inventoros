<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use App\Support\PublicHostGuard;
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

        // Encode the payload ONCE; sign those exact bytes; transmit the same
        // exact bytes via withBody so the receiver's HMAC verification over
        // the raw request body lines up with what we signed. Previously the
        // job signed json_encode($payload) but then passed the array to
        // Http::post, which let Guzzle re-serialise with different escaping
        // — signatures sporadically mismatched for receivers that hashed
        // the raw body (the standard pattern, and the one our own
        // WebhookService::verifySignature documents).
        $payloadJson = json_encode($this->delivery->payload, JSON_UNESCAPED_SLASHES);
        $signature = WebhookService::sign($payloadJson, $webhook->secret);

        $this->delivery->increment('attempts');

        try {
            // Resolve the URL's host at delivery time and reject any non-
            // public address. The create-time URL validator only inspects
            // the host string; DNS rebinding ('attacker.com' resolves to
            // 127.0.0.1 or 169.254.169.254 just before delivery) can slip
            // past it. Re-check here so the actual outbound destination
            // is what the operator intended.
            PublicHostGuard::assertPublic($webhook->url);

            // Disable redirect following so a 302 from an allowlisted host
            // cannot smuggle the request to a private/metadata URL.
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(30)
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => $this->delivery->event,
                    'X-Webhook-Delivery' => (string) $this->delivery->id,
                ])
                ->withBody($payloadJson, 'application/json')
                ->post($webhook->url);

            $this->delivery->update([
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 5000),
                'status' => $response->successful() ? 'success' : 'pending',
                'completed_at' => $response->successful() ? now() : null,
                // Clear next_retry_at on success; populate on HTTP failure
                // so WebhookDelivery::scopeReadyForRetry can answer "what
                // can a retry worker pick up right now?" for ops dashboards
                // and any future polling worker. Without this the scope
                // was dead code — every row's next_retry_at was NULL.
                'next_retry_at' => $response->successful() ? null : $this->nextRetryAt(),
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
                'next_retry_at' => $this->nextRetryAt(),
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
        // Permanent failure: clear next_retry_at so the row no longer
        // appears in WebhookDelivery::scopeReadyForRetry.
        $this->delivery->update([
            'status' => 'failed',
            'response_body' => Str::limit($e->getMessage(), 5000),
            'next_retry_at' => null,
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
     * Compute the time at which this delivery should next be retried,
     * based on the BACKOFF_DELAYS schedule and the current attempt count.
     * Returns null when no further retry is scheduled (max attempts hit).
     */
    protected function nextRetryAt(): ?\Illuminate\Support\Carbon
    {
        // $this->delivery->attempts has just been incremented for the
        // current run. attempts=1 means we just made the first try and
        // the next retry uses BACKOFF_DELAYS[0]=60s. Index = attempts - 1.
        $idx = max(0, $this->delivery->attempts - 1);
        if ($idx >= count(self::BACKOFF_DELAYS)) {
            return null;
        }

        return now()->addSeconds(self::BACKOFF_DELAYS[$idx]);
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
