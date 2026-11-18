<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\ExchangeRates\ExchangeRate;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\ExchangeRates\OrganizationExchangeRate;
use App\Models\User;
use App\Support\ExchangeMath;
use App\Support\OrganizationCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds, fetches and stores exchange rates for an organization.
 *
 * Lookups read stored rates first and ask the provider only when nothing
 * recent enough is stored; a provider that just failed is not asked again
 * until its cool-down passes, so a slow or down provider never holds up
 * page loads. Rates from shareable providers are stored once for every
 * organization (exchange_rates); everything else is stored per
 * organization (organization_exchange_rates) and never read for another.
 *
 * Substitution rules: a rate is only ever taken from the organization's
 * selected provider or, when an administrator configured one, its fallback
 * provider, and the quote always says which. A stored rate older than the
 * organization's freshness window is returned as stale, never as current.
 */
final class ExchangeRateService
{
    public function __construct(private readonly ExchangeRateProviderRegistry $providers) {}

    public function providers(): ExchangeRateProviderRegistry
    {
        return $this->providers;
    }

    public function settingsFor(int $organizationId): ExchangeRateSetting
    {
        return ExchangeRateSetting::forOrganization($organizationId);
    }

    /**
     * The rate for 1 $from in the organization's currency on $date (today
     * when null; a future date is treated as today).
     *
     * $allowFetch false only reads stored rates (no network).
     */
    public function quote(int $organizationId, string $from, CarbonInterface|string|null $date = null, bool $allowFetch = true): RateQuote
    {
        $from = strtoupper(trim($from));
        $to = Organization::currencyFor($organizationId);
        $settings = $this->settingsFor($organizationId);
        $requested = $this->requestedDate($organizationId, $date);
        $staleAfter = max(1, (int) $settings->stale_after_days);

        if ($from === $to) {
            return new RateQuote($from, $to, RateQuote::SAME_CURRENCY, $requested, $settings->provider, rate: '1', staleAfterDays: $staleAfter);
        }

        $primary = $this->lookup($organizationId, $settings, $settings->provider, $from, $to, $requested, $allowFetch);

        if ($primary['fresh'] !== null) {
            return $this->quoteFrom($primary['fresh'], RateQuote::OK, $from, $to, $requested, $settings, fallback: false);
        }

        $fallbackId = $settings->fallback_provider;
        $fallback = null;

        if ($fallbackId !== null && $fallbackId !== '' && $fallbackId !== $settings->provider) {
            $fallback = $this->lookup($organizationId, $settings, $fallbackId, $from, $to, $requested, $allowFetch);

            if ($fallback['fresh'] !== null) {
                return $this->quoteFrom($fallback['fresh'], RateQuote::OK, $from, $to, $requested, $settings, fallback: true, error: $primary['error'], detail: $primary['detail']);
            }
        }

        if ($primary['stale'] !== null) {
            return $this->quoteFrom($primary['stale'], RateQuote::STALE, $from, $to, $requested, $settings, fallback: false, error: $primary['error'], detail: $primary['detail']);
        }

        if ($fallback !== null && $fallback['stale'] !== null) {
            return $this->quoteFrom($fallback['stale'], RateQuote::STALE, $from, $to, $requested, $settings, fallback: true, error: $primary['error'], detail: $primary['detail']);
        }

        return new RateQuote(
            $from, $to, RateQuote::UNAVAILABLE, $requested, $settings->provider,
            provider: $settings->provider,
            providerName: $this->providers->nameFor($settings->provider),
            staleAfterDays: $staleAfter,
            error: $primary['error'] ?? RateQuote::ERROR_NO_RATE,
            errorDetail: $primary['detail'],
        );
    }

    /**
     * Ask a provider for the latest rates for 1 $base in each of $symbols
     * and store them. Used by the synchronizer; one call serves every
     * organization when the provider is shareable.
     *
     * @param  array<int, string>  $symbols
     * @param  array<string, mixed>  $config
     *
     * @throws ExchangeRateException
     */
    public function fetchAndStore(ExchangeRateProvider $provider, string $base, array $symbols, ?CarbonImmutable $date, array $config, ?int $organizationId, CarbonImmutable $requested): FetchedRates
    {
        $shareable = ExchangeRateProviderRegistry::isShareable($provider);
        $scope = $shareable ? null : $organizationId;

        $fetched = $this->callProvider($provider, fn () => $provider->fetch($base, array_values($symbols), $date, $config));

        if ($fetched->base !== $base) {
            throw ExchangeRateException::invalidResponse("{$provider->name()} answered for {$fetched->base} instead of {$base}.");
        }

        // A provider must not answer with a date after the one asked for.
        if ($date !== null && $fetched->effectiveDate->greaterThan($date->startOfDay())) {
            throw ExchangeRateException::invalidResponse("{$provider->name()} returned rates dated after the requested date.");
        }

        $now = CarbonImmutable::now();

        foreach ($fetched->rates as $quote => $rate) {
            $this->store($provider->id(), $scope, $base, $quote, $rate, $fetched->effectiveDate, $now);
            $this->remember($provider->id(), $scope, $base, $quote, $requested, $fetched->effectiveDate);
        }

        Cache::forget($this->cooldownKey($provider->id(), $scope));

        return $fetched;
    }

    /**
     * Record a rate an administrator entered (provider "manual").
     */
    public function storeManual(int $organizationId, string $from, string $rate, CarbonInterface|string $effectiveDate, ?User $enteredBy): OrganizationExchangeRate
    {
        $to = Organization::currencyFor($organizationId);
        $date = CarbonImmutable::parse($effectiveDate)->startOfDay();

        /** @var OrganizationExchangeRate $row */
        $row = $this->store('manual', $organizationId, strtoupper($from), $to, ExchangeMath::rate($rate), $date, CarbonImmutable::now(), $enteredBy?->id);

        return $row;
    }

    /**
     * The newest stored rate per pair for an organization and provider, for
     * the settings page.
     *
     * @param  array<int, string>  $currencies
     * @return array<string, ExchangeRate|OrganizationExchangeRate|null> currency => newest row
     */
    public function latestStored(int $organizationId, string $providerId, array $currencies): array
    {
        $provider = $this->providers->get($providerId);
        $shareable = $provider !== null && ExchangeRateProviderRegistry::isShareable($provider);
        $to = Organization::currencyFor($organizationId);
        $today = OrganizationCalendar::today(Organization::find($organizationId));

        $rows = [];
        foreach ($currencies as $currency) {
            $rows[$currency] = $currency === $to ? null : $this->newestStored($organizationId, $providerId, $shareable, $currency, $to, $today);
        }

        return $rows;
    }

    /**
     * The codes a provider can quote with this organization's settings,
     * cached for a day; null when the provider cannot say right now.
     *
     * @return array<int, string>|null
     */
    public function supportedCurrencies(string $providerId, ExchangeRateSetting $settings): ?array
    {
        $provider = $this->providers->get($providerId);
        if ($provider === null) {
            return null;
        }

        $key = $this->currenciesKey($provider, $settings);
        $config = $settings->configFor($providerId);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $codes = array_values(array_filter(
                array_map(fn ($code) => strtoupper((string) $code), $provider->supportedCurrencies($config)),
                fn (string $code) => FetchedRates::isCurrencyCode($code),
            ));
        } catch (Throwable $e) {
            Log::info('Exchange rate provider could not list its currencies', ['provider' => $providerId, 'exception' => $e::class, 'error' => ProviderErrorMessage::redact($e->getMessage())]);

            return null;
        }

        Cache::put($key, $codes, now()->addDay());

        return $codes;
    }

    /**
     * Today in the organization's time zone; a future date becomes today.
     */
    public function requestedDate(int $organizationId, CarbonInterface|string|null $date): CarbonImmutable
    {
        $today = OrganizationCalendar::today(Organization::find($organizationId))->startOfDay();

        if ($date === null || $date === '') {
            return $today;
        }

        try {
            $parsed = CarbonImmutable::parse($date instanceof CarbonInterface ? $date->toDateString() : (string) $date)->startOfDay();
        } catch (Throwable) {
            return $today;
        }

        return $parsed->greaterThan($today) ? $today : $parsed;
    }

    /**
     * Whether a provider failure is still cooling down for this scope.
     */
    public function coolingDown(string $providerId, ?int $scope): bool
    {
        return Cache::has($this->cooldownKey($providerId, $scope));
    }

    /**
     * Stop asking a provider for a while after a transient failure.
     */
    public function coolDown(string $providerId, ?int $scope, ExchangeRateException $e): void
    {
        $seconds = max((int) config('exchange_rates.failure_cooldown_seconds', 300), (int) ($e->retryAfterSeconds ?? 0));
        Cache::put($this->cooldownKey($providerId, $scope), ProviderErrorMessage::redact($e->getMessage()), now()->addSeconds(min($seconds, 86400)));
    }

    /**
     * Whether core asks providers about this code at all: only the
     * currencies the application supports (config/currencies.php).
     */
    public static function isSupportedCurrency(string $code): bool
    {
        $code = strtoupper(trim($code));

        return FetchedRates::isCurrencyCode($code)
            && array_key_exists($code, (array) config('currencies.supported', []));
    }

    /**
     * The earliest date a rate is ever asked for. Older purchase orders are
     * saved without a provider rate.
     */
    public static function earliestDate(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) config('exchange_rates.earliest_date', '1999-01-04'))->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::parse('1999-01-04');
        }
    }

    /**
     * Run a provider call. Plugins supply providers, so anything a provider
     * throws other than ExchangeRateException is logged and reported as the
     * provider being unavailable: it fails this provider and organization
     * only, never the sync batch or the page that asked.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     *
     * @throws ExchangeRateException
     */
    public function callProvider(ExchangeRateProvider $provider, callable $call): mixed
    {
        try {
            return $call();
        } catch (ExchangeRateException $e) {
            throw $e;
        } catch (Throwable $e) {
            $message = ProviderErrorMessage::redact($e->getMessage());

            Log::warning('Exchange rate provider failed unexpectedly', ['provider' => $provider->id(), 'exception' => $e::class, 'error' => $message]);

            throw ExchangeRateException::unavailable($provider->name().' failed unexpectedly'.($message !== '' ? ': '.$message : '.'), $e);
        }
    }

    /**
     * @return array{fresh: ExchangeRate|OrganizationExchangeRate|null, stale: ExchangeRate|OrganizationExchangeRate|null, error: string|null, detail: string|null}
     */
    private function lookup(int $organizationId, ExchangeRateSetting $settings, string $providerId, string $from, string $to, CarbonImmutable $requested, bool $allowFetch): array
    {
        $result = ['fresh' => null, 'stale' => null, 'error' => null, 'detail' => null];
        $provider = $this->providers->get($providerId);

        if ($provider === null) {
            $result['error'] = RateQuote::ERROR_PROVIDER_UNAVAILABLE;
            $result['detail'] = "The exchange rate provider \"{$providerId}\" is not available. Its plugin may have been deactivated.";

            return $result;
        }

        $capabilities = $provider->capabilities();
        $shareable = ExchangeRateProviderRegistry::isShareable($provider);
        $scope = $shareable ? null : $organizationId;
        $staleAfter = max(1, (int) $settings->stale_after_days);
        $today = OrganizationCalendar::today(Organization::find($organizationId))->startOfDay();

        $row = $this->newestStored($organizationId, $providerId, $shareable, $from, $to, $requested);
        $known = $row !== null && (
            $row->effective_date->toDateString() === $requested->toDateString()
            || Cache::get($this->memoKey($providerId, $scope, $from, $to, $requested)) === $row->effective_date->toDateString()
        );

        $canFetch = $allowFetch
            && $capabilities->automatic
            && ($capabilities->historical || $requested->equalTo($today));

        if (! $known && $canFetch) {
            $refusal = $this->refusal($provider, $settings, $scope, $from, $to, $requested);

            if ($refusal !== null) {
                // Never sent to the provider: an unknown or unsupported code,
                // a date before the floor, or a request it already refused.
                $result['error'] = RateQuote::ERROR_UNSUPPORTED;
                $result['detail'] = $refusal;
            } elseif ($this->coolingDown($providerId, $scope)) {
                $result['error'] = RateQuote::ERROR_FETCH_FAILED;
                $result['detail'] = (string) Cache::get($this->cooldownKey($providerId, $scope));
            } else {
                try {
                    $this->fetchLocked($provider, $settings->configFor($providerId), $organizationId, $scope, $from, $to, $requested, $today);
                    $row = $this->newestStored($organizationId, $providerId, $shareable, $from, $to, $requested);
                } catch (Throwable $e) {
                    $e = $e instanceof ExchangeRateException ? $e : ExchangeRateException::unavailable($provider->name().' failed unexpectedly.', $e);
                    $message = ProviderErrorMessage::redact($e->getMessage());

                    if ($e->isTransient()) {
                        $this->coolDown($providerId, $scope, $e);
                    } elseif ($e->reason === ExchangeRateException::UNSUPPORTED) {
                        // Asking again for the same request gets the same
                        // answer: remember it instead of asking on every page load.
                        Cache::put($this->refusedKey($providerId, $scope, $from, $to, $requested), $message, now()->addDay());
                    }

                    Log::info('Exchange rate lookup failed', ['provider' => $providerId, 'from' => $from, 'to' => $to, 'reason' => $e->reason, 'error' => $message]);
                    $result['error'] = $e->reason === ExchangeRateException::UNSUPPORTED ? RateQuote::ERROR_UNSUPPORTED : RateQuote::ERROR_FETCH_FAILED;
                    $result['detail'] = $message;
                }
            }
        }

        if ($row === null) {
            $result['error'] ??= RateQuote::ERROR_NO_RATE;

            return $result;
        }

        $age = (int) $row->effective_date->copy()->startOfDay()->diffInDays($requested);
        $result[$age <= $staleAfter ? 'fresh' : 'stale'] = $row;

        return $result;
    }

    /**
     * Why a request must not be sent to the provider, or null when it may.
     */
    private function refusal(ExchangeRateProvider $provider, ExchangeRateSetting $settings, ?int $scope, string $from, string $to, CarbonImmutable $requested): ?string
    {
        foreach ([$from, $to] as $code) {
            if (! self::isSupportedCurrency($code)) {
                return "{$code} is not a supported currency.";
            }
        }

        if ($requested->lessThan(self::earliestDate())) {
            return 'Exchange rates are not looked up for dates before '.self::earliestDate()->toDateString().'.';
        }

        // The provider's own list, when it is already known (it is loaded
        // for the settings page and kept for a day).
        $listed = Cache::get($this->currenciesKey($provider, $settings));
        if (is_array($listed) && $listed !== []) {
            foreach ([$from, $to] as $code) {
                if (! in_array($code, $listed, true)) {
                    return "{$provider->name()} does not quote {$code}.";
                }
            }
        }

        $refused = Cache::get($this->refusedKey($provider->id(), $scope, $from, $to, $requested));

        return is_string($refused) ? $refused : null;
    }

    private function currenciesKey(ExchangeRateProvider $provider, ExchangeRateSetting $settings): string
    {
        $config = $settings->configFor($provider->id());

        return 'exchange-rates:currencies:'.$provider->id().':'.(ExchangeRateProviderRegistry::isShareable($provider) ? 'shared' : $settings->organization_id).':'.sha1((string) json_encode($config));
    }

    private function refusedKey(string $providerId, ?int $scope, string $from, string $to, CarbonImmutable $requested): string
    {
        return 'exchange-rates:refused:'.$providerId.':'.($scope ?? 'shared').':'.$from.':'.$to.':'.$requested->toDateString();
    }

    /**
     * Fetch under a lock, so concurrent lookups of the same pair and date
     * (many organizations opening a purchase order at once) make one
     * request; the others find what the first stored.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ExchangeRateException
     */
    private function fetchLocked(ExchangeRateProvider $provider, array $config, int $organizationId, ?int $scope, string $from, string $to, CarbonImmutable $requested, CarbonImmutable $today): void
    {
        $key = 'exchange-rates:fetch:'.$provider->id().':'.($scope ?? 'shared').':'.$from.':'.$to.':'.$requested->toDateString();
        $date = $requested->equalTo($today) ? null : $requested;

        $run = function () use ($provider, $config, $organizationId, $scope, $from, $to, $requested, $date): void {
            if (Cache::has($this->memoKey($provider->id(), $scope, $from, $to, $requested))) {
                return;
            }

            $fetched = $this->fetchAndStore($provider, $from, [$to], $date, $config, $organizationId, $requested);

            if (! array_key_exists($to, $fetched->rates)) {
                throw ExchangeRateException::unsupported("{$provider->name()} does not quote {$from} in {$to}.");
            }
        };

        try {
            Cache::lock($key, 30)->block(10, $run);
        } catch (LockTimeoutException) {
            // Another request is still fetching; use what is stored.
        }
    }

    /**
     * @return ExchangeRate|OrganizationExchangeRate|null
     */
    private function newestStored(int $organizationId, string $providerId, bool $shareable, string $from, string $to, CarbonImmutable $onOrBefore): ?Model
    {
        $query = $shareable
            ? ExchangeRate::query()
            : OrganizationExchangeRate::query()->withoutGlobalScopes()->where('organization_id', $organizationId);

        return $query->pair($providerId, $from, $to)
            ->whereDate('effective_date', '<=', $onOrBefore->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    private function store(string $providerId, ?int $organizationId, string $base, string $quote, string $rate, CarbonImmutable $effectiveDate, CarbonImmutable $retrievedAt, ?int $enteredBy = null): Model
    {
        if ($organizationId === null) {
            $row = ExchangeRate::query()
                ->pair($providerId, $base, $quote)
                ->whereDate('effective_date', $effectiveDate->toDateString())
                ->first() ?? new ExchangeRate([
                    'provider' => $providerId,
                    'base_currency' => $base,
                    'quote_currency' => $quote,
                    'effective_date' => $effectiveDate,
                ]);
        } else {
            $row = OrganizationExchangeRate::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->pair($providerId, $base, $quote)
                ->whereDate('effective_date', $effectiveDate->toDateString())
                ->first() ?? new OrganizationExchangeRate([
                    'organization_id' => $organizationId,
                    'provider' => $providerId,
                    'base_currency' => $base,
                    'quote_currency' => $quote,
                    'effective_date' => $effectiveDate,
                ]);

            $row->entered_by = $enteredBy;
        }

        $row->rate = $rate;
        $row->retrieved_at = $retrievedAt;

        try {
            // In its own savepoint: on PostgreSQL a failed insert would
            // otherwise abort a surrounding transaction.
            DB::transaction(fn () => $row->save());
        } catch (UniqueConstraintViolationException) {
            // A concurrent request stored the same pair and date first; the
            // rate for a published date is the same, so theirs stands.
        }

        return $row;
    }

    /**
     * Remember which effective date the provider returned for a requested
     * date, so the lookup does not ask again: briefly for today (a newer
     * rate is published later in the day), for weeks for a past date.
     */
    private function remember(string $providerId, ?int $scope, string $from, string $to, CarbonImmutable $requested, CarbonImmutable $effective): void
    {
        $today = CarbonImmutable::now()->startOfDay();
        $ttl = $requested->lessThan($today->subDay())
            ? now()->addDays(30)
            : now()->addMinutes(max(1, (int) config('exchange_rates.refresh_after_minutes', 360)));

        Cache::put($this->memoKey($providerId, $scope, $from, $to, $requested), $effective->toDateString(), $ttl);
    }

    private function memoKey(string $providerId, ?int $scope, string $from, string $to, CarbonImmutable $requested): string
    {
        return 'exchange-rates:memo:'.$providerId.':'.($scope ?? 'shared').':'.$from.':'.$to.':'.$requested->toDateString();
    }

    private function cooldownKey(string $providerId, ?int $scope): string
    {
        return 'exchange-rates:cooldown:'.$providerId.':'.($scope ?? 'shared');
    }

    private function quoteFrom(ExchangeRate|OrganizationExchangeRate $row, string $status, string $from, string $to, CarbonImmutable $requested, ExchangeRateSetting $settings, bool $fallback, ?string $error = null, ?string $detail = null): RateQuote
    {
        return new RateQuote(
            $from, $to, $status, $requested, $settings->provider,
            rate: ExchangeMath::rate((string) $row->rate),
            provider: $row->provider,
            providerName: $this->providers->nameFor($row->provider),
            effectiveDate: CarbonImmutable::parse($row->effective_date->toDateString()),
            retrievedAt: $row->retrieved_at ? CarbonImmutable::parse($row->retrieved_at) : null,
            fallback: $fallback,
            staleAfterDays: max(1, (int) $settings->stale_after_days),
            error: $error,
            errorDetail: $detail,
        );
    }
}
