<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\ExchangeRates\ExchangeRate;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\ExchangeRates\OrganizationExchangeRate;
use App\Models\User;
use App\Services\ExchangeRates\ExchangeRateException;
use App\Services\ExchangeRates\ExchangeRateProviderRegistry;
use App\Services\ExchangeRates\ExchangeRateService;
use App\Services\ExchangeRates\RateQuote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rate lookups: stored rates first, one request for many organizations,
 * freshness and staleness, fallback only when configured, a gone provider,
 * and tenant isolation of private rates.
 */
final class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExchangeRateService $rates;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-16 12:00:00');
        Carbon::setTestNow('2026-11-16 12:00:00');
        Http::preventStrayRequests();
        $this->rates = app(ExchangeRateService::class);
    }

    protected function tearDown(): void
    {
        app(ExchangeRateProviderRegistry::class)->clear();
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function org(string $name, string $currency = 'HUF'): Organization
    {
        return Organization::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'currency' => $currency, 'timezone' => 'UTC']);
    }

    private function fakeFrankfurter(float $rate = 364.35, string $date = '2026-11-16'): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(['amount' => 1.0, 'base' => 'EUR', 'date' => $date, 'rates' => ['HUF' => $rate]])]);
    }

    public function test_same_currency_needs_no_rate(): void
    {
        $org = $this->org('Same', 'EUR');

        $quote = $this->rates->quote($org->id, 'eur');

        $this->assertSame(RateQuote::SAME_CURRENCY, $quote->status);
        $this->assertSame('1', $quote->rate);
        Http::assertNothingSent();
    }

    public function test_a_rate_is_fetched_once_and_shared_by_every_organization(): void
    {
        $this->fakeFrankfurter();
        $first = $this->org('Budapest');
        $second = $this->org('Szeged');

        $a = $this->rates->quote($first->id, 'EUR');
        $b = $this->rates->quote($second->id, 'EUR');

        $this->assertSame(RateQuote::OK, $a->status);
        $this->assertSame('364.35', $a->rate);
        $this->assertSame('frankfurter', $a->provider);
        $this->assertSame('2026-11-16', $a->effectiveDate->toDateString());
        $this->assertSame('364.35', $b->rate);
        Http::assertSentCount(1);
        $this->assertSame(1, ExchangeRate::count());
        $this->assertSame(0, OrganizationExchangeRate::withoutGlobalScopes()->count());
    }

    public function test_a_weekend_lookup_stores_the_effective_date_and_does_not_ask_again(): void
    {
        CarbonImmutable::setTestNow('2026-11-15 12:00:00');
        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->fakeFrankfurter(365.6, '2026-11-13');
        $org = $this->org('Weekend');

        $quote = $this->rates->quote($org->id, 'EUR');
        $again = $this->rates->quote($org->id, 'EUR');

        $this->assertSame(RateQuote::OK, $quote->status);
        $this->assertSame('2026-11-13', $quote->effectiveDate->toDateString());
        $this->assertSame('2026-11-15', $quote->requestedDate->toDateString());
        $this->assertSame(2, $quote->ageDays());
        $this->assertSame('2026-11-13', ExchangeRate::first()->effective_date->toDateString());
        $this->assertSame('365.6', $again->rate);
        Http::assertSentCount(1);
    }

    public function test_a_past_order_date_asks_for_that_date(): void
    {
        Http::fake(['api.frankfurter.dev/v1/2026-10-01*' => Http::response(['base' => 'EUR', 'date' => '2026-10-01', 'rates' => ['HUF' => 390.12]])]);
        $org = $this->org('History');

        $quote = $this->rates->quote($org->id, 'EUR', '2026-10-01');

        $this->assertSame('390.12', $quote->rate);
        $this->assertSame('2026-10-01', $quote->effectiveDate->toDateString());
    }

    public function test_a_failed_provider_shows_the_stored_rate_marked_stale_beyond_the_window(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $org = $this->org('Stale');
        ExchangeRate::create(['provider' => 'frankfurter', 'base_currency' => 'EUR', 'quote_currency' => 'HUF', 'rate' => '361.5', 'effective_date' => '2026-11-06', 'retrieved_at' => now()->subDays(10)]);

        $quote = $this->rates->quote($org->id, 'EUR');

        $this->assertSame(RateQuote::STALE, $quote->status);
        $this->assertFalse($quote->isUsable());
        $this->assertSame('361.5', $quote->rate);
        $this->assertSame('2026-11-06', $quote->effectiveDate->toDateString());
        $this->assertSame(10, $quote->ageDays());
        $this->assertSame(RateQuote::ERROR_FETCH_FAILED, $quote->error);
        $this->assertFalse($quote->fallback);
    }

    public function test_a_recent_stored_rate_is_used_when_the_provider_fails(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $org = $this->org('Recent');
        ExchangeRate::create(['provider' => 'frankfurter', 'base_currency' => 'EUR', 'quote_currency' => 'HUF', 'rate' => '363', 'effective_date' => '2026-11-13', 'retrieved_at' => now()->subDays(3)]);

        $quote = $this->rates->quote($org->id, 'EUR');

        $this->assertSame(RateQuote::OK, $quote->status);
        $this->assertSame('2026-11-13', $quote->effectiveDate->toDateString());
    }

    public function test_after_a_failure_the_provider_is_not_asked_again_during_the_cooldown(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $org = $this->org('Cooldown');

        $this->assertSame(RateQuote::UNAVAILABLE, $this->rates->quote($org->id, 'EUR')->status);
        $this->assertSame(RateQuote::UNAVAILABLE, $this->rates->quote($org->id, 'EUR')->status);

        Http::assertSentCount(1);
    }

    public function test_the_stale_window_is_configurable_per_organization(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $org = $this->org('Strict');
        ExchangeRateSetting::forOrganization($org->id)->update(['stale_after_days' => 1]);
        ExchangeRate::create(['provider' => 'frankfurter', 'base_currency' => 'EUR', 'quote_currency' => 'HUF', 'rate' => '363', 'effective_date' => '2026-11-13', 'retrieved_at' => now()]);

        $this->assertSame(RateQuote::STALE, $this->rates->quote($org->id, 'EUR')->status);
    }

    public function test_another_provider_is_used_only_when_configured_as_the_fallback_and_says_so(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $org = $this->org('Fallback');
        $this->rates->storeManual($org->id, 'EUR', '360', '2026-11-16', null);

        $withoutFallback = $this->rates->quote($org->id, 'EUR');
        $this->assertSame(RateQuote::UNAVAILABLE, $withoutFallback->status, 'A manual rate must not stand in unless it is the configured fallback.');

        ExchangeRateSetting::forOrganization($org->id)->update(['fallback_provider' => 'manual']);
        $quote = $this->rates->quote($org->id, 'EUR');

        $this->assertSame(RateQuote::OK, $quote->status);
        $this->assertTrue($quote->fallback);
        $this->assertSame('manual', $quote->provider);
        $this->assertSame('frankfurter', $quote->selectedProvider);
        $this->assertSame('360', $quote->rate);
    }

    public function test_the_manual_provider_never_contacts_anything(): void
    {
        $org = $this->org('Offline');
        ExchangeRateSetting::forOrganization($org->id)->update(['provider' => 'manual']);

        $this->assertSame(RateQuote::UNAVAILABLE, $this->rates->quote($org->id, 'EUR')->status);

        $this->rates->storeManual($org->id, 'EUR', '362.5', '2026-11-15', null);
        $quote = $this->rates->quote($org->id, 'EUR');

        $this->assertSame(RateQuote::OK, $quote->status);
        $this->assertSame('362.5', $quote->rate);
        Http::assertNothingSent();
    }

    public function test_a_provider_whose_plugin_is_gone_degrades_clearly(): void
    {
        $org = $this->org('Gone');
        ExchangeRateSetting::forOrganization($org->id)->update(['provider' => 'acme-fx']);

        $quote = $this->rates->quote($org->id, 'EUR');

        $this->assertSame(RateQuote::UNAVAILABLE, $quote->status);
        $this->assertSame(RateQuote::ERROR_PROVIDER_UNAVAILABLE, $quote->error);
        $this->assertSame('acme-fx', $quote->provider);
        Http::assertNothingSent();
    }

    public function test_an_unsupported_pair_is_reported_as_such(): void
    {
        Http::fake(['*' => Http::response(['base' => 'EUR', 'date' => '2026-11-16', 'rates' => []])]);
        $org = $this->org('Rouble', 'RUB');

        $quote = $this->rates->quote($org->id, 'EUR');

        $this->assertSame(RateQuote::UNAVAILABLE, $quote->status);
        $this->assertSame(RateQuote::ERROR_UNSUPPORTED, $quote->error);
    }

    public function test_a_licensed_providers_rates_stay_with_the_organization_that_fetched_them(): void
    {
        $fake = new FakeExchangeRateProvider;
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);

        $buyer = $this->org('Licensed');
        $other = $this->org('Other');
        foreach ([$buyer, $other] as $org) {
            ExchangeRateSetting::forOrganization($org->id)->update(['provider' => 'acme-fx', 'provider_config' => ['acme-fx' => ['api_key' => 'key-'.$org->id]]]);
        }

        $this->assertSame('366.1', $this->rates->quote($buyer->id, 'EUR')->rate);
        $this->assertSame(0, ExchangeRate::count(), 'Licensed rates must never land in the shared table.');
        $this->assertSame(1, OrganizationExchangeRate::withoutGlobalScopes()->where('organization_id', $buyer->id)->count());

        // The other organization makes its own request with its own key
        // instead of reading the first one's licensed data.
        $this->assertSame('366.1', $this->rates->quote($other->id, 'EUR')->rate);
        $this->assertCount(2, $fake->calls);
        $this->assertSame('key-'.$buyer->id, $fake->calls[0]['config']['api_key']);
        $this->assertSame('key-'.$other->id, $fake->calls[1]['config']['api_key']);
    }

    public function test_manual_rates_are_invisible_to_other_organizations(): void
    {
        $owner = $this->org('Owner');
        $intruder = $this->org('Intruder');
        foreach ([$owner, $intruder] as $org) {
            ExchangeRateSetting::forOrganization($org->id)->update(['provider' => 'manual']);
        }
        $this->rates->storeManual($owner->id, 'EUR', '362.5', '2026-11-16', null);

        $this->assertSame(RateQuote::UNAVAILABLE, $this->rates->quote($intruder->id, 'EUR')->status);

        $user = User::create(['name' => 'I', 'email' => 'i@intruder.test', 'password' => bcrypt('x'), 'organization_id' => $intruder->id, 'role' => 'admin']);
        $this->actingAs($user);
        $this->assertSame(0, OrganizationExchangeRate::count(), 'The tenant scope hides another organization\'s rates.');
        $this->assertNull(ExchangeRateSetting::where('organization_id', $owner->id)->first());
    }

    public function test_concurrent_lookups_reuse_what_the_first_one_stored(): void
    {
        $this->fakeFrankfurter();
        $orgs = collect(range(1, 5))->map(fn ($i) => $this->org("Org{$i}"));

        foreach ($orgs as $org) {
            $this->assertSame('364.35', $this->rates->quote($org->id, 'EUR')->rate);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['symbols'] === 'HUF');
    }

    public function test_an_exception_from_a_provider_never_escapes_a_lookup(): void
    {
        $fake = new FakeExchangeRateProvider;
        $fake->failWith = ExchangeRateException::invalidResponse('garbage');
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);
        $org = $this->org('Garbage');
        ExchangeRateSetting::forOrganization($org->id)->update(['provider' => 'acme-fx', 'provider_config' => ['acme-fx' => ['api_key' => 'k']]]);

        $quote = $this->rates->quote($org->id, 'EUR');

        $this->assertSame(RateQuote::UNAVAILABLE, $quote->status);
        $this->assertSame(RateQuote::ERROR_FETCH_FAILED, $quote->error);
        $this->assertSame('garbage', $quote->errorDetail);
    }
}
