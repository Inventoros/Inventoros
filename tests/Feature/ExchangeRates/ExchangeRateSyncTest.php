<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Jobs\SyncExchangeRatesJob;
use App\Models\ExchangeRates\ExchangeRate;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\ExchangeRates\OrganizationExchangeRate;
use App\Services\ExchangeRates\ExchangeRateException;
use App\Services\ExchangeRates\ExchangeRateProviderRegistry;
use App\Services\ExchangeRates\ExchangeRateService;
use App\Services\ExchangeRates\ExchangeRateSynchronizer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Scheduled synchronization: one request per provider and foreign currency
 * for every organization, weekends, rate limits, outages, timeouts,
 * malformed responses and unsupported currencies, with the outcome stored
 * for the settings page.
 */
final class ExchangeRateSyncTest extends TestCase
{
    use ExchangeRateFixtures, RefreshDatabase;

    private ExchangeRateSynchronizer $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atTime();
        Http::preventStrayRequests();
        $this->sync = app(ExchangeRateSynchronizer::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    private function syncingOrganization(string $name, string $currency, array $supplierCurrencies, array $settings = []): ExchangeRateSetting
    {
        $org = $this->organization($name, $currency);
        foreach ($supplierCurrencies as $code) {
            $this->supplier($org, $code);
        }

        $setting = ExchangeRateSetting::forOrganization($org->id);
        $setting->update($settings + ['sync_enabled' => true]);

        return $setting->fresh();
    }

    public function test_identical_requests_are_made_once_for_every_organization(): void
    {
        Http::fake(['api.frankfurter.dev/v1/latest*' => function (Request $request) {
            return Http::response(['base' => $request['base'], 'date' => '2026-11-16', 'rates' => match ($request['base']) {
                'EUR' => ['HUF' => 364.35, 'USD' => 1.1014],
                'USD' => ['HUF' => 330.8],
            }]);
        }]);

        $a = $this->syncingOrganization('Alpha', 'HUF', ['EUR', 'USD']);
        $b = $this->syncingOrganization('Beta', 'HUF', ['EUR']);
        $c = $this->syncingOrganization('Gamma', 'USD', ['EUR', 'USD']);

        $outcomes = $this->sync->run($this->sync->due());

        // EUR -> HUF,USD once; USD -> HUF once. Never one request per organization.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['base'] === 'EUR' && $request['symbols'] === 'HUF,USD');
        Http::assertSent(fn (Request $request) => $request['base'] === 'USD' && $request['symbols'] === 'HUF');

        foreach ([$a, $b, $c] as $setting) {
            $this->assertSame('success', $outcomes[$setting->organization_id]['status']);
            $fresh = $setting->fresh();
            $this->assertSame('success', $fresh->last_sync_status);
            $this->assertNull($fresh->last_sync_error);
            $this->assertSame('2026-11-17 12:00:00', $fresh->next_sync_at->toDateTimeString());
            $this->assertSame('2026-11-16 12:00:00', $fresh->last_synced_at->toDateTimeString());
        }

        $this->assertSame(3, ExchangeRate::count());
        $this->assertSame('364.3500000000', ExchangeRate::where('base_currency', 'EUR')->where('quote_currency', 'HUF')->value('rate'));
    }

    public function test_only_due_organizations_with_sync_on_are_picked(): void
    {
        $due = $this->syncingOrganization('Due', 'HUF', ['EUR']);
        $later = $this->syncingOrganization('Later', 'HUF', ['EUR']);
        $later->forceFill(['next_sync_at' => now()->addHour()])->save();
        $off = $this->syncingOrganization('Off', 'HUF', ['EUR'], ['sync_enabled' => false]);

        $ids = $this->sync->due()->pluck('organization_id')->all();

        $this->assertSame([$due->organization_id], $ids);
        $this->assertNotContains($off->organization_id, $ids);
    }

    public function test_a_weekend_sync_stores_fridays_rate_under_fridays_date(): void
    {
        $this->atTime('2026-11-15 09:00:00');
        $this->fakeFrankfurter(365.6, '2026-11-13');
        $this->syncingOrganization('Weekend', 'HUF', ['EUR']);

        $this->sync->run($this->sync->due());

        $this->assertSame('2026-11-13', ExchangeRate::first()->effective_date->toDateString());
    }

    public function test_a_rate_limit_stops_further_requests_and_backs_off(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '7200'])]);
        $setting = $this->syncingOrganization('Limited', 'HUF', ['EUR', 'USD', 'GBP']);

        $outcome = $this->sync->run([$setting])[$setting->organization_id];

        Http::assertSentCount(1);
        $this->assertSame('failed', $outcome['status']);
        $fresh = $setting->fresh();
        $this->assertSame(1, $fresh->sync_failures);
        $this->assertStringContainsString('429', $fresh->last_sync_error);
        $this->assertNull($fresh->last_synced_at);
        // Retry-After (2 hours) beats the first back-off step (15 minutes).
        $this->assertSame('2026-11-16 14:00:00', $fresh->next_sync_at->toDateTimeString());
    }

    public function test_server_errors_back_off_step_by_step_then_wait_for_the_regular_run(): void
    {
        Http::fake(['*' => Http::response('oops', 500)]);
        $setting = $this->syncingOrganization('Flaky', 'HUF', ['EUR']);

        $expected = ['2026-11-16 12:15:00', '2026-11-16 13:00:00', '2026-11-16 16:00:00', '2026-11-17 12:00:00'];
        foreach ($expected as $i => $next) {
            Cache::flush();
            $this->sync->run([$setting->fresh()]);
            $this->assertSame($i + 1, $setting->fresh()->sync_failures);
            $this->assertSame($next, $setting->fresh()->next_sync_at->toDateTimeString());
        }

        // Recovery resets the counter.
        Cache::flush();
        Http::swap(new Factory);
        $this->fakeFrankfurter();
        $this->sync->run([$setting->fresh()]);
        $this->assertSame(0, $setting->fresh()->sync_failures);
        $this->assertSame('success', $setting->fresh()->last_sync_status);
    }

    public function test_a_timeout_is_recorded_as_a_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 8001 milliseconds'));
        $setting = $this->syncingOrganization('Timeout', 'HUF', ['EUR']);

        $this->sync->run([$setting]);

        $this->assertSame('failed', $setting->fresh()->last_sync_status);
        $this->assertStringContainsString('timed out', $setting->fresh()->last_sync_error);
        $this->assertSame(0, ExchangeRate::count());
    }

    public function test_a_malformed_response_is_rejected_and_not_retried_early(): void
    {
        Http::fake(['*' => Http::response(['base' => 'EUR', 'date' => 'yesterday', 'rates' => ['HUF' => 'cheap']])]);
        $setting = $this->syncingOrganization('Garbled', 'HUF', ['EUR']);

        $this->sync->run([$setting]);

        $fresh = $setting->fresh();
        $this->assertSame('failed', $fresh->last_sync_status);
        $this->assertSame(0, $fresh->sync_failures);
        $this->assertSame('2026-11-17 12:00:00', $fresh->next_sync_at->toDateTimeString());
        $this->assertSame(0, ExchangeRate::count());
    }

    public function test_an_unsupported_currency_gives_a_partial_result(): void
    {
        Http::fake(['api.frankfurter.dev/v1/latest*' => function (Request $request) {
            return $request['base'] === 'RUB'
                ? Http::response(['message' => 'not found'], 404)
                : Http::response(['base' => 'EUR', 'date' => '2026-11-16', 'rates' => ['HUF' => 364.35]]);
        }]);
        $setting = $this->syncingOrganization('Mixed', 'HUF', ['EUR', 'RUB']);

        $outcome = $this->sync->run([$setting])[$setting->organization_id];

        $this->assertSame('partial', $outcome['status']);
        $this->assertStringContainsString('RUB/HUF', $setting->fresh()->last_sync_error);
        $this->assertNotNull($setting->fresh()->last_synced_at);
        $this->assertSame(1, ExchangeRate::count());
    }

    public function test_a_provider_whose_plugin_is_gone_is_reported(): void
    {
        $setting = $this->syncingOrganization('Orphan', 'HUF', ['EUR'], ['provider' => 'acme-fx']);

        $outcome = $this->sync->run([$setting])[$setting->organization_id];

        Http::assertNothingSent();
        $this->assertSame('failed', $outcome['status']);
        $this->assertStringContainsString('acme-fx', $setting->fresh()->last_sync_error);
    }

    public function test_a_licensed_provider_is_asked_per_organization_with_its_own_settings(): void
    {
        $fake = new FakeExchangeRateProvider;
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);

        $one = $this->syncingOrganization('One', 'HUF', ['EUR'], ['provider' => 'acme-fx', 'provider_config' => ['acme-fx' => ['api_key' => 'one']]]);
        $two = $this->syncingOrganization('Two', 'HUF', ['EUR'], ['provider' => 'acme-fx', 'provider_config' => ['acme-fx' => ['api_key' => 'two']]]);

        $this->sync->run($this->sync->due());

        $this->assertCount(2, $fake->calls);
        $this->assertSame(0, ExchangeRate::count());
        $this->assertSame(1, OrganizationExchangeRate::withoutGlobalScopes()->where('organization_id', $one->organization_id)->count());
        $this->assertSame(1, OrganizationExchangeRate::withoutGlobalScopes()->where('organization_id', $two->organization_id)->count());
    }

    public function test_the_manual_provider_has_nothing_to_fetch(): void
    {
        $setting = $this->syncingOrganization('Offline', 'HUF', ['EUR'], ['provider' => 'manual']);

        $this->assertSame('success', $this->sync->run([$setting])[$setting->organization_id]['status']);
        Http::assertNothingSent();
    }

    public function test_the_command_queues_one_job_for_the_due_organizations_and_claims_them(): void
    {
        Queue::fake();
        $a = $this->syncingOrganization('A', 'HUF', ['EUR']);
        $b = $this->syncingOrganization('B', 'HUF', ['EUR']);

        $this->artisan('exchange-rates:sync')->assertSuccessful();

        Queue::assertPushed(SyncExchangeRatesJob::class, 1);
        Queue::assertPushed(SyncExchangeRatesJob::class, fn (SyncExchangeRatesJob $job) => $job->organizationIds === [$a->organization_id, $b->organization_id]);
        $this->assertTrue($a->fresh()->next_sync_at->isFuture());

        // Claimed: the next hourly run does not queue them again.
        $this->artisan('exchange-rates:sync')->expectsOutputToContain('No organization is due')->assertSuccessful();
        Queue::assertPushed(SyncExchangeRatesJob::class, 1);
    }

    public function test_the_job_and_the_now_option_run_the_sync(): void
    {
        $this->fakeFrankfurter();
        $setting = $this->syncingOrganization('Inline', 'HUF', ['EUR']);

        $this->artisan('exchange-rates:sync', ['--now' => true])->expectsOutputToContain('success')->assertSuccessful();
        $this->assertSame('success', $setting->fresh()->last_sync_status);

        (new SyncExchangeRatesJob([$setting->organization_id]))->handle($this->sync);
        $this->assertSame(1, ExchangeRate::count());
    }

    public function test_the_sync_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'exchange-rates:sync'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }

    public function test_a_failure_is_cooled_down_for_page_lookups_too(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $setting = $this->syncingOrganization('Cooling', 'HUF', ['EUR']);

        $this->sync->run([$setting]);
        app(ExchangeRateService::class)->quote($setting->organization_id, 'EUR');

        Http::assertSentCount(1);
    }

    public function test_an_exception_never_stops_the_other_organizations(): void
    {
        $fake = new FakeExchangeRateProvider;
        $fake->failWith = ExchangeRateException::misconfigured('Acme FX needs an API key.');
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);
        $this->fakeFrankfurter();

        $broken = $this->syncingOrganization('Broken', 'HUF', ['EUR'], ['provider' => 'acme-fx']);
        $fine = $this->syncingOrganization('Fine', 'HUF', ['EUR']);

        $outcomes = $this->sync->run($this->sync->due());

        $this->assertSame('failed', $outcomes[$broken->organization_id]['status']);
        $this->assertSame('success', $outcomes[$fine->organization_id]['status']);
    }
}
