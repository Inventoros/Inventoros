<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates\Providers;

use App\Services\ExchangeRates\ExchangeRateException;
use App\Services\ExchangeRates\ExchangeRateProvider;
use App\Services\ExchangeRates\FetchedRates;
use App\Services\ExchangeRates\ProviderCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Frankfurter (https://frankfurter.dev): the European Central Bank's daily
 * reference rates, public, free and without an API key.
 *
 * The ECB publishes on TARGET working days around 16:00 CET. Asking for a
 * weekend or holiday returns the last published rates and their date, which
 * is stored as the effective date. The data is the same for everyone, so it
 * is shareable: one request serves every organization.
 */
final class FrankfurterProvider implements ExchangeRateProvider
{
    public const ID = 'frankfurter';

    public function id(): string
    {
        return self::ID;
    }

    public function name(): string
    {
        return 'Frankfurter (European Central Bank)';
    }

    public function description(): string
    {
        return 'Daily reference rates published by the European Central Bank on working days. No account or API key needed.';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(automatic: true, historical: true, shareable: true);
    }

    public function configFields(): array
    {
        return [];
    }

    public function supportedCurrencies(array $config): array
    {
        $body = $this->decode($this->get('currencies', []));

        if (! is_array($body) || $body === [] || array_is_list($body)) {
            throw ExchangeRateException::invalidResponse('Frankfurter returned an unexpected currency list.');
        }

        $codes = array_values(array_filter(
            array_map(fn ($code) => strtoupper((string) $code), array_keys($body)),
            fn (string $code) => FetchedRates::isCurrencyCode($code),
        ));
        sort($codes);

        return $codes;
    }

    public function fetch(string $base, array $symbols, ?CarbonImmutable $date, array $config): FetchedRates
    {
        $path = $date === null ? 'latest' : $date->toDateString();
        $body = $this->decode($this->get($path, ['base' => $base, 'symbols' => implode(',', $symbols)]));

        if (! is_array($body) || ! is_string($body['base'] ?? null) || ! is_string($body['date'] ?? null) || ! is_array($body['rates'] ?? null)) {
            throw ExchangeRateException::invalidResponse('Frankfurter returned an unexpected response.');
        }

        if (strtoupper($body['base']) !== strtoupper($base)) {
            throw ExchangeRateException::invalidResponse("Frankfurter answered for {$body['base']} instead of {$base}.");
        }

        // Only what was asked for: an unrequested currency is ignored.
        $rates = array_intersect_key(
            array_change_key_case($body['rates'], CASE_UPPER),
            array_flip($symbols),
        );

        return new FetchedRates($base, $body['date'], $rates);
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws ExchangeRateException
     */
    private function get(string $path, array $query): Response
    {
        $url = rtrim((string) config('exchange_rates.frankfurter.url'), '/').'/'.$path;

        try {
            $response = Http::acceptJson()
                ->timeout(max(1, (int) config('exchange_rates.frankfurter.timeout', 8)))
                ->connectTimeout(max(1, (int) config('exchange_rates.frankfurter.connect_timeout', 4)))
                ->withoutRedirecting()
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw ExchangeRateException::unavailable('Frankfurter could not be reached: '.$e->getMessage(), $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');

            throw ExchangeRateException::rateLimited(
                'Frankfurter is rate limiting requests (HTTP 429).',
                is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null,
            );
        }

        // 404: unknown base currency, or a date before the data starts.
        // 422: an invalid pair. Asking again will not help.
        if (in_array($status, [400, 404, 422], true)) {
            $decoded = $this->decode($response, quiet: true);
            $message = is_array($decoded) ? ($decoded['message'] ?? null) : null;

            throw ExchangeRateException::unsupported('Frankfurter cannot quote this request'.(is_string($message) ? " ({$message})." : '.'));
        }

        throw ExchangeRateException::unavailable("Frankfurter answered with HTTP {$status}.");
    }

    /**
     * @return mixed
     */
    private function decode(Response $response, bool $quiet = false)
    {
        try {
            return json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            if ($quiet) {
                return null;
            }

            throw ExchangeRateException::invalidResponse('Frankfurter returned a response that is not JSON.');
        }
    }
}
