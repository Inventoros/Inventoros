<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use Carbon\CarbonImmutable;

/**
 * The answer to "what is 1 $from in $to for this organization on this date":
 * a rate with where it came from and how old it is, or why there is none.
 *
 * - same_currency: no conversion needed (rate 1).
 * - ok: a rate within the organization's freshness window, from the selected
 *   provider or, when $fallback is true, from the configured fallback.
 * - stale: the newest stored rate is older than the window. It is shown with
 *   its date and only used when an authorized user confirms it.
 * - unavailable: no rate at all; $error says why.
 */
final class RateQuote
{
    public const SAME_CURRENCY = 'same_currency';

    public const OK = 'ok';

    public const STALE = 'stale';

    public const UNAVAILABLE = 'unavailable';

    /** The selected provider is not registered (a plugin was deactivated). */
    public const ERROR_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /** The provider was asked and failed (down, rate limited, bad response). */
    public const ERROR_FETCH_FAILED = 'fetch_failed';

    /** The provider cannot quote this pair or date. */
    public const ERROR_UNSUPPORTED = 'unsupported';

    /** Nothing stored and nothing could be fetched (manual provider, history not offered). */
    public const ERROR_NO_RATE = 'no_rate';

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $status,
        public readonly CarbonImmutable $requestedDate,
        public readonly string $selectedProvider,
        public readonly ?string $rate = null,
        public readonly ?string $provider = null,
        public readonly ?string $providerName = null,
        public readonly ?CarbonImmutable $effectiveDate = null,
        public readonly ?CarbonImmutable $retrievedAt = null,
        public readonly bool $fallback = false,
        public readonly int $staleAfterDays = 4,
        public readonly ?string $error = null,
        public readonly ?string $errorDetail = null,
    ) {}

    public function isUsable(): bool
    {
        return in_array($this->status, [self::OK, self::SAME_CURRENCY], true);
    }

    public function hasRate(): bool
    {
        return $this->rate !== null;
    }

    /**
     * Days between the rate's effective date and the date it is for.
     */
    public function ageDays(): ?int
    {
        if ($this->effectiveDate === null) {
            return null;
        }

        return max(0, (int) $this->effectiveDate->startOfDay()->diffInDays($this->requestedDate->startOfDay()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'status' => $this->status,
            'rate' => $this->rate,
            'provider' => $this->provider,
            'provider_name' => $this->providerName,
            'selected_provider' => $this->selectedProvider,
            'requested_date' => $this->requestedDate->toDateString(),
            'effective_date' => $this->effectiveDate?->toDateString(),
            'retrieved_at' => $this->retrievedAt?->toIso8601String(),
            'age_days' => $this->ageDays(),
            'stale_after_days' => $this->staleAfterDays,
            'is_fallback' => $this->fallback,
            'error' => $this->error,
            'error_detail' => $this->errorDetail,
        ];
    }
}
