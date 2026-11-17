<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Services\ExchangeRates\ExchangeRateException;
use App\Services\ExchangeRates\ExchangeRateProvider;
use App\Services\ExchangeRates\FetchedRates;
use App\Services\ExchangeRates\ProviderCapabilities;
use App\Services\ExchangeRates\ProviderConfigField;
use Carbon\CarbonImmutable;

/**
 * A plugin-style provider for tests: licensed per customer (not shareable),
 * needs an API key, answers from a fixed table and counts its calls.
 */
final class FakeExchangeRateProvider implements ExchangeRateProvider
{
    /** @var array<int, array{base: string, symbols: array<int, string>, date: string|null, config: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, string> "EUR/HUF" => rate */
    public array $rates = ['EUR/HUF' => '366.10', 'USD/HUF' => '331.20', 'EUR/USD' => '1.1'];

    public ?ExchangeRateException $failWith = null;

    public string $effectiveDate = '2026-11-16';

    public function __construct(
        private readonly string $id = 'acme-fx',
        private readonly bool $shareable = false,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return 'Acme FX';
    }

    public function description(): string
    {
        return 'Licensed rates for tests.';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(automatic: true, historical: true, shareable: $this->shareable);
    }

    public function configFields(): array
    {
        return [
            new ProviderConfigField('api_key', 'API key', 'secret', required: true),
            new ProviderConfigField('account', 'Account', 'text'),
        ];
    }

    public function supportedCurrencies(array $config): array
    {
        return ['EUR', 'HUF', 'USD'];
    }

    public function fetch(string $base, array $symbols, ?CarbonImmutable $date, array $config): FetchedRates
    {
        $this->calls[] = ['base' => $base, 'symbols' => $symbols, 'date' => $date?->toDateString(), 'config' => $config];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        if (($config['api_key'] ?? null) === null) {
            throw ExchangeRateException::misconfigured('Acme FX needs an API key.');
        }

        $rates = [];
        foreach ($symbols as $symbol) {
            if (isset($this->rates["{$base}/{$symbol}"])) {
                $rates[$symbol] = $this->rates["{$base}/{$symbol}"];
            }
        }

        return new FetchedRates($base, $this->effectiveDate, $rates);
    }
}
