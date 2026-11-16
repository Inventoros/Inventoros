<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates\Providers;

use App\Services\ExchangeRates\ExchangeRateException;
use App\Services\ExchangeRates\ExchangeRateProvider;
use App\Services\ExchangeRates\FetchedRates;
use App\Services\ExchangeRates\ProviderCapabilities;
use Carbon\CarbonImmutable;

/**
 * Rates an organization's administrators enter on Settings > Exchange rates.
 * It never contacts anything: choosing it as the provider keeps an install
 * entirely offline, and choosing it as the fallback lets a manual rate stand
 * in, labelled as such, when the main provider is down.
 */
final class ManualProvider implements ExchangeRateProvider
{
    public const ID = 'manual';

    public function id(): string
    {
        return self::ID;
    }

    public function name(): string
    {
        return 'Manual rates';
    }

    public function description(): string
    {
        return 'Rates entered by an administrator. Nothing is fetched from outside.';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(automatic: false, historical: true, shareable: false);
    }

    public function configFields(): array
    {
        return [];
    }

    public function supportedCurrencies(array $config): array
    {
        return array_keys((array) config('currencies.supported', []));
    }

    public function fetch(string $base, array $symbols, ?CarbonImmutable $date, array $config): FetchedRates
    {
        throw ExchangeRateException::unsupported('Manual rates are entered by an administrator, not fetched.');
    }
}
