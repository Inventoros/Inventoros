<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Webhook;
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

    /**
     * Upper bounds for what the webhook_delivery_retry_policy filter may ask
     * for: at most 10 tries, each back-off between 1 second and 24 hours.
     */
    public const MAX_POLICY_TRIES = 10;
    public const MAX_POLICY_BACKOFF_SECONDS = 86400;

    /**
     * Headers a webhook_delivery_request filter may not set: core's own
     * signature and identification headers, and transport headers that
     * would change where or how the request is sent.
     */
    private const RESERVED_HEADERS = [
        'x-webhook-signature', 'x-webhook-event', 'x-webhook-delivery',
        'host', 'content-type', 'content-length', 'transfer-encoding',
        'connection', 'expect', 'te', 'upgrade', 'proxy-authorization',
    ];

    private const MAX_EXTRA_HEADERS = 20;

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
        $this->applyRetryPolicy();
    }

    /**
     * HOOK: webhook_delivery_retry_policy lets a plugin choose the number of
     * tries and the back-off for this delivery, within MAX_POLICY_TRIES and
     * MAX_POLICY_BACKOFF_SECONDS. Anything malformed keeps the defaults.
     */
    private function applyRetryPolicy(): void
    {
        $webhook = $this->delivery->webhook;

        if ($webhook === null) {
            return;
        }

        try {
            $policy = apply_filters('webhook_delivery_retry_policy', [
                'tries' => $this->tries,
                'backoff' => $this->backoff,
            ], $this->delivery, $webhook);
        } catch (Throwable $e) {
            Log::warning('webhook_delivery_retry_policy filter failed; using the default policy', [
                'delivery_id' => $this->delivery->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! is_array($policy)) {
            return;
        }

        if (isset($policy['tries']) && is_int($policy['tries'])) {
            $this->tries = max(1, min(self::MAX_POLICY_TRIES, $policy['tries']));
        }

        if (isset($policy['backoff']) && is_array($policy['backoff'])) {
            $backoff = [];
            foreach (array_slice(array_values($policy['backoff']), 0, self::MAX_POLICY_TRIES) as $seconds) {
                if (is_int($seconds)) {
                    $backoff[] = max(1, min(self::MAX_POLICY_BACKOFF_SECONDS, $seconds));
                }
            }

            if ($backoff !== []) {
                $this->backoff = $backoff;
            }
        }
    }

    /**
     * Clamp a requested timeout to 1..TIMEOUT_SECONDS.
     */
    public static function clampTimeout(int $seconds): int
    {
        return max(1, min(self::TIMEOUT_SECONDS, $seconds));
    }

    /**
     * HOOK: webhook_delivery_request lets a plugin replace the body (for
     * example a payload template), add headers (for example an
     * Authorization header) and shorten the timeout. It cannot change the
     * URL, override core's headers or the content type, or inject header
     * lines; core signs whatever body comes back.
     *
     * @return array{body: string, headers: array<string, string>, timeout: int}
     */
    private function request(Webhook $webhook, string $payloadJson): array
    {
        $default = ['body' => $payloadJson, 'headers' => [], 'timeout' => self::TIMEOUT_SECONDS];

        try {
            $request = apply_filters('webhook_delivery_request', $default, $this->delivery, $webhook);
        } catch (Throwable $e) {
            Log::warning('webhook_delivery_request filter failed; sending the standard request', [
                'delivery_id' => $this->delivery->id,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }

        if (! is_array($request)) {
            return $default;
        }

        $headers = [];
        foreach (is_array($request['headers'] ?? null) ? $request['headers'] : [] as $name => $value) {
            if (count($headers) >= self::MAX_EXTRA_HEADERS) {
                break;
            }
            if (! is_string($name) || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,128}$/', $name) !== 1) {
                continue;
            }
            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                continue;
            }
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = (string) $value;
            if (strlen($value) > 8192 || preg_match('/[\r\n\0]/', $value) === 1) {
                continue;
            }
            $headers[$name] = $value;
        }

        return [
            'body' => is_string($request['body'] ?? null) ? $request['body'] : $payloadJson,
            'headers' => $headers,
            'timeout' => is_int($request['timeout'] ?? null) ? self::clampTimeout($request['timeout']) : self::TIMEOUT_SECONDS,
        ];
    }

    /**
     * HOOK: webhook_delivery_attempted, once per attempt (success, HTTP
     * error, refused destination or connection failure). A listener that
     * throws is logged and ignored.
     */
    private function reportAttempt(Webhook $webhook, bool $successful, ?int $status, int $startedAt, ?string $error): void
    {
        $attempt = (int) $this->delivery->attempts;

        try {
            do_action('webhook_delivery_attempted', $this->delivery, $webhook, [
                'successful' => $successful,
                'status' => $status,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'error' => $error === null ? null : Str::limit($error, 1000),
                'attempt' => $attempt,
                'will_retry' => ! $successful && $attempt < $this->tries,
            ]);
        } catch (Throwable $e) {
            Log::warning('webhook_delivery_attempted listener failed', [
                'delivery_id' => $this->delivery->id,
                'error' => $e->getMessage(),
            ]);
        }
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
        $request = $this->request($webhook, json_encode($this->delivery->payload, JSON_UNESCAPED_SLASHES));
        $payloadJson = $request['body'];
        $signature = WebhookService::sign($payloadJson, $webhook->secret);

        $this->delivery->increment('attempts');
        $startedAt = hrtime(true);
        $reported = false;

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
                ->timeout($request['timeout'])
                ->withHeaders($request['headers'])
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

            $this->reportAttempt($webhook, $response->successful(), $response->status(), $startedAt, $response->successful() ? null : "Webhook returned {$response->status()}");
            $reported = true;

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

            if (! $reported) {
                $this->reportAttempt($webhook, false, null, $startedAt, $e->getMessage());
            }

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
        $delays = is_array($this->backoff) && $this->backoff !== [] ? array_values($this->backoff) : self::BACKOFF_DELAYS;
        $idx = max(0, $this->delivery->attempts - 1);
        if ($idx >= count($delays)) {
            return null;
        }

        return now()->addSeconds($delays[$idx]);
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
