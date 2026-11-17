<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use Carbon\CarbonImmutable;

/**
 * A source of exchange rates. Core ships Frankfurter (ECB reference rates)
 * and "manual" (rates an administrator enters); plugins add more with
 * register_exchange_rate_provider().
 *
 * A provider only talks to its source. Core stores what it returns, decides
 * when to ask, validates the response, applies freshness rules and never
 * substitutes another provider's rate unless an administrator configured
 * that provider as the fallback.
 *
 * @api
 */
interface ExchangeRateProvider
{
    /**
     * Stable identifier stored with every rate and purchase order snapshot,
     * lowercase letters, digits, dots and dashes. A plugin's provider id is
     * its slug or starts with "{slug}.".
     */
    public function id(): string;

    /**
     * Name shown to administrators and on purchase orders.
     */
    public function name(): string;

    /**
     * One sentence for the settings page.
     */
    public function description(): string;

    public function capabilities(): ProviderCapabilities;

    /**
     * Settings an administrator fills in for this provider (an API key, an
     * account id, a base URL). Secret fields are encrypted at rest and never
     * sent back to the browser.
     *
     * @return array<int, ProviderConfigField>
     */
    public function configFields(): array;

    /**
     * ISO 4217 codes this provider can quote, given its settings. Called for
     * the settings page and validation; core caches the result for a day.
     *
     * @param  array<string, mixed>  $config  The organization's settings for this provider
     * @return array<int, string>
     *
     * @throws ExchangeRateException when the list cannot be loaded
     */
    public function supportedCurrencies(array $config): array;

    /**
     * Rates for 1 $base in each of $symbols, as published for $date (or the
     * latest published rates when $date is null). A provider that publishes
     * on working days only returns the most recent rates on or before $date
     * and reports that date as the effective date.
     *
     * A symbol the provider cannot quote is left out of the result; a request
     * it cannot serve at all throws.
     *
     * @param  array<int, string>  $symbols  Upper-case ISO 4217 codes, never $base
     * @param  array<string, mixed>  $config  The organization's settings for this provider
     *
     * @throws ExchangeRateException
     */
    public function fetch(string $base, array $symbols, ?CarbonImmutable $date, array $config): FetchedRates;
}
