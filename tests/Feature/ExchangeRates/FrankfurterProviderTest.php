<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Services\ExchangeRates\ExchangeRateException;
use App\Services\ExchangeRates\Providers\FrankfurterProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Frankfurter provider: response parsing and every failure mode, with
 * the HTTP layer faked (no network).
 */
final class FrankfurterProviderTest extends TestCase
{
    private FrankfurterProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FrankfurterProvider;
        Http::preventStrayRequests();
    }

    public function test_it_fetches_the_latest_rates(): void
    {
        Http::fake(['api.frankfurter.dev/v1/latest*' => Http::response(['amount' => 1.0, 'base' => 'EUR', 'date' => '2026-11-16', 'rates' => ['HUF' => 364.35, 'USD' => 1.1014]])]);

        $rates = $this->provider->fetch('EUR', ['HUF', 'USD'], null, []);

        $this->assertSame('EUR', $rates->base);
        $this->assertSame('2026-11-16', $rates->effectiveDate->toDateString());
        $this->assertSame(['HUF' => '364.35', 'USD' => '1.1014'], $rates->rates);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.frankfurter.dev/v1/latest')
            && $request['base'] === 'EUR' && $request['symbols'] === 'HUF,USD');
    }

    public function test_a_weekend_request_reports_the_last_published_date(): void
    {
        Http::fake(['api.frankfurter.dev/v1/2026-11-14*' => Http::response(['amount' => 1.0, 'base' => 'EUR', 'date' => '2026-11-13', 'rates' => ['HUF' => 365.6]])]);

        $rates = $this->provider->fetch('EUR', ['HUF'], CarbonImmutable::parse('2026-11-14'), []);

        $this->assertSame('2026-11-13', $rates->effectiveDate->toDateString());
        $this->assertSame(['HUF' => '365.6'], $rates->rates);
    }

    public function test_an_unquoted_symbol_is_left_out_and_unrequested_ones_ignored(): void
    {
        Http::fake(['*' => Http::response(['base' => 'EUR', 'date' => '2026-11-16', 'rates' => ['HUF' => 364.35, 'GBP' => 0.87]])]);

        $this->assertSame(['HUF' => '364.35'], $this->provider->fetch('EUR', ['HUF', 'RUB'], null, [])->rates);
    }

    public function test_rate_limiting_is_reported_with_the_retry_after(): void
    {
        Http::fake(['*' => Http::response('Too many', 429, ['Retry-After' => '120'])]);

        $e = $this->failure(fn () => $this->provider->fetch('EUR', ['HUF'], null, []));

        $this->assertSame(ExchangeRateException::RATE_LIMITED, $e->reason);
        $this->assertSame(120, $e->retryAfterSeconds);
        $this->assertTrue($e->isTransient());
    }

    public function test_a_server_error_is_transient(): void
    {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $e = $this->failure(fn () => $this->provider->fetch('EUR', ['HUF'], null, []));

        $this->assertSame(ExchangeRateException::UNAVAILABLE, $e->reason);
        $this->assertTrue($e->isTransient());
    }

    public function test_a_timeout_is_transient(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $e = $this->failure(fn () => $this->provider->fetch('EUR', ['HUF'], null, []));

        $this->assertSame(ExchangeRateException::UNAVAILABLE, $e->reason);
        $this->assertStringContainsString('timed out', $e->getMessage());
    }

    public function test_an_unsupported_currency_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        $e = $this->failure(fn () => $this->provider->fetch('RUB', ['HUF'], null, []));

        $this->assertSame(ExchangeRateException::UNSUPPORTED, $e->reason);
        $this->assertFalse($e->isTransient());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedResponses(): array
    {
        return [
            'not json' => ['<html>maintenance</html>'],
            'missing rates' => [['base' => 'EUR', 'date' => '2026-11-16']],
            'wrong base' => [['base' => 'USD', 'date' => '2026-11-16', 'rates' => ['HUF' => 330]]],
            'bad date' => [['base' => 'EUR', 'date' => '16/11/2026', 'rates' => ['HUF' => 364.35]]],
            'impossible date' => [['base' => 'EUR', 'date' => '2026-02-30', 'rates' => ['HUF' => 364.35]]],
            'future date' => [['base' => 'EUR', 'date' => '2099-01-01', 'rates' => ['HUF' => 364.35]]],
            'negative rate' => [['base' => 'EUR', 'date' => '2026-11-16', 'rates' => ['HUF' => -1]]],
            'zero rate' => [['base' => 'EUR', 'date' => '2026-11-16', 'rates' => ['HUF' => 0]]],
            'text rate' => [['base' => 'EUR', 'date' => '2026-11-16', 'rates' => ['HUF' => 'lots']]],
        ];
    }

    #[DataProvider('malformedResponses')]
    public function test_a_malformed_response_is_rejected(mixed $body): void
    {
        Http::fake(['*' => Http::response($body)]);

        $e = $this->failure(fn () => $this->provider->fetch('EUR', ['HUF'], null, []));

        $this->assertSame(ExchangeRateException::INVALID_RESPONSE, $e->reason);
    }

    public function test_it_lists_supported_currencies(): void
    {
        Http::fake(['*/currencies' => Http::response(['EUR' => 'Euro', 'HUF' => 'Hungarian Forint', 'USD' => 'US Dollar'])]);

        $this->assertSame(['EUR', 'HUF', 'USD'], $this->provider->supportedCurrencies([]));
    }

    public function test_the_base_url_can_point_at_a_self_hosted_instance(): void
    {
        config(['exchange_rates.frankfurter.url' => 'https://fx.internal.example/v1/']);
        Http::fake(['fx.internal.example/*' => Http::response(['base' => 'EUR', 'date' => '2026-11-16', 'rates' => ['HUF' => 364.35]])]);

        $this->provider->fetch('EUR', ['HUF'], null, []);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://fx.internal.example/v1/latest'));
    }

    private function failure(callable $call): ExchangeRateException
    {
        try {
            $call();
        } catch (ExchangeRateException $e) {
            return $e;
        }

        $this->fail('Expected an ExchangeRateException.');
    }
}
