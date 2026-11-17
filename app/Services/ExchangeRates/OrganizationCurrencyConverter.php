<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\Inventory\ProductSupplier;
use App\Support\ExchangeMath;
use Carbon\CarbonInterface;
use DateTimeZone;
use Exception;

/**
 * Shows an amount recorded in another currency in the organization's
 * currency, for reference (supplier costs and their history on the product
 * page).
 *
 * Only stored rates are read: viewing a page never calls a provider. Rates
 * are stored by the scheduled sync, by purchase orders and by manual entry.
 * Nothing is invented: with no stored rate the result says so and carries
 * no amount, and a rate older than the organization's freshness window is
 * marked stale with its date.
 */
final class OrganizationCurrencyConverter
{
    public const OK = RateQuote::OK;

    public const STALE = RateQuote::STALE;

    public const UNAVAILABLE = RateQuote::UNAVAILABLE;

    /** @var array<string, RateQuote> */
    private array $quotes = [];

    /** @var array<int, Organization|null> */
    private array $organizations = [];

    /** @var array<int, string> */
    private array $currencies = [];

    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * $amount in $currency, converted to the organization's currency at the
     * stored rate for $date (today when null), or null when there is
     * nothing to convert: no amount, no known currency, or the amount is
     * already in the organization's currency.
     *
     * @return array{currency: string, status: string, amount: string|null, rate: string|null, effective_date: string|null, requested_date: string, age_days: int|null, stale_after_days: int, is_fallback: bool, provider_name: string|null}|null
     */
    public function toOrganizationCurrency(int $organizationId, int|float|string|null $amount, ?string $currency, CarbonInterface|string|null $date = null): ?array
    {
        $from = ProductSupplier::normalizeCurrency($currency);

        if ($amount === null || $amount === '' || $from === null) {
            return null;
        }

        $to = $this->currencies[$organizationId] ??= Organization::currencyFor($organizationId);

        if ($from === $to) {
            return null;
        }

        $quote = $this->quote($organizationId, $from, $this->dateFor($organizationId, $date));
        $usable = $quote->hasRate() && in_array($quote->status, [RateQuote::OK, RateQuote::STALE], true);

        return [
            'currency' => $to,
            'status' => $usable ? $quote->status : self::UNAVAILABLE,
            'amount' => $usable ? ExchangeMath::convert((string) $amount, (string) $quote->rate, $to) : null,
            'rate' => $usable ? $quote->rate : null,
            'effective_date' => $usable ? $quote->effectiveDate?->toDateString() : null,
            'requested_date' => $quote->requestedDate->toDateString(),
            'age_days' => $usable ? $quote->ageDays() : null,
            'stale_after_days' => $quote->staleAfterDays,
            'is_fallback' => $usable && $quote->fallback,
            'provider_name' => $usable ? $quote->providerName : null,
        ];
    }

    private function quote(int $organizationId, string $from, ?string $date): RateQuote
    {
        return $this->quotes[$organizationId.'|'.$from.'|'.($date ?? 'today')] ??= $this->rates->quote($organizationId, $from, $date, allowFetch: false);
    }

    /**
     * A moment (a recorded_at timestamp) becomes its calendar date in the
     * organization's time zone; a date string is taken as it is.
     */
    private function dateFor(int $organizationId, CarbonInterface|string|null $date): ?string
    {
        if (! $date instanceof CarbonInterface) {
            return $date === null || $date === '' ? null : (string) $date;
        }

        $organization = array_key_exists($organizationId, $this->organizations)
            ? $this->organizations[$organizationId]
            : $this->organizations[$organizationId] = Organization::find($organizationId);

        try {
            $timezone = new DateTimeZone($organization?->timezone ?: 'UTC');
        } catch (Exception) {
            $timezone = new DateTimeZone('UTC');
        }

        return $date->copy()->setTimezone($timezone)->toDateString();
    }
}
