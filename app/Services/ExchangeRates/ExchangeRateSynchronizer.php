<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\Inventory\Supplier;
use App\Support\OrganizationCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Keeps each organization's rates current.
 *
 * For every organization in the run it works out the pairs it needs (each
 * foreign currency its active suppliers use or an administrator listed, to
 * the organization's currency), then groups identical requests: with a
 * shareable provider every organization that needs EUR -> HUF, HUF -> ...
 * from the same provider is served by one request per foreign currency.
 * A rate limit or outage stops further requests to that provider in the
 * run, and each organization's outcome (success, partial, failed, with the
 * error and the next attempt, backing off after failures) is stored on its
 * settings for the admin page.
 */
final class ExchangeRateSynchronizer
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateProviderRegistry $providers,
    ) {}

    /**
     * Organizations whose automatic synchronization is due.
     *
     * @return Collection<int, ExchangeRateSetting>
     */
    public function due(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return ExchangeRateSetting::query()->withoutGlobalScopes()
            ->where('sync_enabled', true)
            ->where(fn ($query) => $query->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', $now))
            ->whereHas('organization', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get();
    }

    /**
     * The foreign currencies an organization needs rates for: those its
     * active suppliers bill in plus those an administrator listed, never
     * its own currency.
     *
     * @return array<int, string>
     */
    public function currenciesFor(ExchangeRateSetting $settings): array
    {
        $base = Organization::currencyFor((int) $settings->organization_id);

        $supplierCurrencies = Supplier::withoutGlobalScopes()
            ->where('organization_id', $settings->organization_id)
            ->where('is_active', true)
            ->whereNotNull('currency')
            ->distinct()
            ->pluck('currency')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->all();

        $codes = array_unique([...$settings->configuredCurrencies(), ...$supplierCurrencies]);
        $codes = array_values(array_filter($codes, fn (string $code) => $code !== $base && FetchedRates::isCurrencyCode($code)));
        sort($codes);

        return $codes;
    }

    /**
     * Synchronize these organizations' latest rates.
     *
     * @param  iterable<int, ExchangeRateSetting>  $settings
     * @return array<int, array{status: string, error: string|null}> organization id => outcome
     */
    public function run(iterable $settings): array
    {
        $now = CarbonImmutable::now();

        /** @var array<string, array{provider: ExchangeRateProvider, base: string, symbols: array<string, true>, config: array<string, mixed>, scope: int|null, organizations: array<int, true>}> $groups */
        $groups = [];
        /** @var array<int, array{settings: ExchangeRateSetting, to: string, needed: array<int, string>, got: array<string, true>, errors: array<int, string>, unsupported: array<int, string>, retryAfter: int|null, transient: bool}> $orgs */
        $orgs = [];

        foreach ($settings as $setting) {
            $organizationId = (int) $setting->organization_id;
            $to = Organization::currencyFor($organizationId);
            $needed = $this->currenciesFor($setting);
            $orgs[$organizationId] = ['settings' => $setting, 'to' => $to, 'needed' => $needed, 'got' => [], 'errors' => [], 'unsupported' => [], 'retryAfter' => null, 'transient' => false];

            $provider = $this->providers->get($setting->provider);

            if ($provider === null) {
                $orgs[$organizationId]['errors'][] = "The exchange rate provider \"{$setting->provider}\" is not available. Its plugin may have been deactivated; choose another provider.";

                continue;
            }

            if (! $provider->capabilities()->automatic) {
                // Manual rates: nothing to fetch.
                continue;
            }

            $config = $setting->configFor($provider->id());
            $shareable = $provider->capabilities()->shareable;
            $scope = $shareable ? null : $organizationId;

            foreach ($needed as $from) {
                // A shareable provider's request is the same for everyone with
                // the same settings: one group per provider, settings and
                // foreign currency, collecting every organization's currency.
                $key = implode('|', [$provider->id(), $scope ?? 'shared', sha1((string) json_encode($config)), $from]);

                $groups[$key] ??= ['provider' => $provider, 'base' => $from, 'symbols' => [], 'config' => $config, 'scope' => $scope, 'organizations' => []];
                $groups[$key]['symbols'][$to] = true;
                $groups[$key]['organizations'][$organizationId] = true;
            }
        }

        /** @var array<string, ExchangeRateException> $stopped provider|scope => the failure that stopped it */
        $stopped = [];

        foreach ($groups as $group) {
            $provider = $group['provider'];
            $stopKey = $provider->id().'|'.($group['scope'] ?? 'shared');
            $symbols = array_keys($group['symbols']);
            sort($symbols);

            $failure = $stopped[$stopKey] ?? null;
            $fetched = null;

            if ($failure === null) {
                try {
                    $requested = OrganizationCalendar::today(Organization::find(array_key_first($group['organizations'])))->startOfDay();
                    $fetched = $this->rates->fetchAndStore($provider, $group['base'], $symbols, null, $group['config'], $group['scope'], $requested);
                } catch (ExchangeRateException $e) {
                    $failure = $e;

                    if ($e->isTransient()) {
                        // Do not hammer a provider that is down or limiting us.
                        $stopped[$stopKey] = $e;
                        $this->rates->coolDown($provider->id(), $group['scope'], $e);
                    }

                    Log::warning('Exchange rate sync request failed', ['provider' => $provider->id(), 'base' => $group['base'], 'reason' => $e->reason, 'error' => $e->getMessage()]);
                }
            }

            foreach (array_keys($group['organizations']) as $organizationId) {
                $to = $orgs[$organizationId]['to'];

                if ($fetched !== null) {
                    if (array_key_exists($to, $fetched->rates)) {
                        $orgs[$organizationId]['got'][$group['base']] = true;
                    } else {
                        $orgs[$organizationId]['unsupported'][] = "{$group['base']}/{$to}";
                    }

                    continue;
                }

                if ($failure->reason === ExchangeRateException::UNSUPPORTED) {
                    $orgs[$organizationId]['unsupported'][] = "{$group['base']}/{$to}";
                } else {
                    $orgs[$organizationId]['errors'][] = "{$group['base']}/{$to}: {$failure->getMessage()}";
                    $orgs[$organizationId]['transient'] = $orgs[$organizationId]['transient'] || $failure->isTransient();
                    $orgs[$organizationId]['retryAfter'] = max($orgs[$organizationId]['retryAfter'] ?? 0, $failure->retryAfterSeconds ?? 0) ?: null;
                }
            }
        }

        $outcomes = [];
        foreach ($orgs as $organizationId => $org) {
            $outcomes[$organizationId] = $this->record($org, $now);
        }

        return $outcomes;
    }

    /**
     * @param  array{settings: ExchangeRateSetting, to: string, needed: array<int, string>, got: array<string, true>, errors: array<int, string>, unsupported: array<int, string>, retryAfter: int|null, transient: bool}  $org
     * @return array{status: string, error: string|null}
     */
    private function record(array $org, CarbonImmutable $now): array
    {
        $settings = $org['settings'];
        $messages = $org['errors'];

        if ($org['unsupported'] !== []) {
            $messages[] = 'The provider does not quote '.implode(', ', $org['unsupported']).'.';
        }

        $status = match (true) {
            $messages === [] => ExchangeRateSetting::STATUS_SUCCESS,
            $org['got'] !== [] => ExchangeRateSetting::STATUS_PARTIAL,
            default => ExchangeRateSetting::STATUS_FAILED,
        };

        $settings->last_sync_attempt_at = $now;
        $settings->last_sync_status = $status;
        $settings->last_sync_error = $messages === [] ? null : mb_substr(implode(' ', $messages), 0, 2000);

        if ($status !== ExchangeRateSetting::STATUS_FAILED) {
            $settings->last_synced_at = $now;
        }

        // Transient failures retry with back-off; anything else (success, an
        // unsupported pair, a missing provider) waits for the next regular run.
        if ($org['transient'] && $org['errors'] !== []) {
            $settings->sync_failures = min(65535, (int) $settings->sync_failures + 1);
            $backoff = array_values((array) config('exchange_rates.retry_backoff_minutes', [15, 60, 240]));
            $minutes = $backoff[$settings->sync_failures - 1] ?? $settings->frequencyMinutes();
            $seconds = max($minutes * 60, (int) ($org['retryAfter'] ?? 0));
            $settings->next_sync_at = $now->addSeconds($seconds);
        } else {
            $settings->sync_failures = 0;
            $settings->next_sync_at = $now->addMinutes($settings->frequencyMinutes());
        }

        $settings->save();

        return ['status' => $status, 'error' => $settings->last_sync_error];
    }
}
