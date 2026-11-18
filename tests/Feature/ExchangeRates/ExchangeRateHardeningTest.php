<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\ExchangeRates\ExchangeRate;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\ExchangeRates\OrganizationExchangeRate;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Services\ExchangeRates\ExchangeRateException;
use App\Services\ExchangeRates\ExchangeRateProviderRegistry;
use App\Services\ExchangeRates\ExchangeRateService;
use App\Services\ExchangeRates\ExchangeRateSynchronizer;
use App\Services\ExchangeRates\Providers\FrankfurterProvider;
use App\Services\ExchangeRates\RateQuote;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Pre-release hardening of exchange rate lookups: bounded and throttled
 * form lookups, codes and dates that never reach a provider, provider
 * exceptions that fail one provider and organization only, provider error
 * detail kept from PO users and redacted for administrators, and plugin
 * providers with per-organization settings stored per organization.
 */
final class ExchangeRateHardeningTest extends TestCase
{
    use ExchangeRateFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Supplier $eurSupplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atTime();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();

        $this->org = $this->organization();
        $this->admin = $this->user($this->org);
        $this->eurSupplier = $this->supplier($this->org, 'EUR');
        $this->product = $this->product($this->org);
    }

    protected function tearDown(): void
    {
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    private function draft(string $currency, string $orderDate = '2026-11-16'): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create($this->org->id, $this->admin, [
            'supplier_id' => $this->eurSupplier->id,
            'order_date' => $orderDate,
            'currency' => $currency,
            'tax' => '0',
            'shipping' => '0',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => '10.00']],
        ]);
    }

    private function useProvider(Organization $organization, string $provider, array $config = []): void
    {
        ExchangeRateSetting::forOrganization($organization->id)->update(
            ['provider' => $provider, 'sync_enabled' => true] + ($config === [] ? [] : ['provider_config' => [$provider => $config]]),
        );
    }

    // 1. Lookup limits

    public function test_the_quote_endpoint_only_takes_supported_currencies_and_bounded_dates(): void
    {
        $this->fakeFrankfurter();
        $quote = fn (array $query) => $this->actingAs($this->admin)->getJson(route('purchase-orders.exchange-rate', $query));

        $quote(['currency' => 'XYZ'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $quote(['currency' => 'EUR', 'date' => '2026-11-17'])->assertStatus(422)->assertJsonValidationErrors('date');
        $quote(['currency' => 'EUR', 'date' => '1998-12-31'])->assertStatus(422)->assertJsonValidationErrors('date');
        Http::assertNothingSent();

        $quote(['currency' => 'eur', 'date' => '2026-11-16'])->assertOk()->assertJsonPath('data.rate', '364.35');
    }

    public function test_the_quote_endpoint_is_throttled_per_user(): void
    {
        $this->fakeFrankfurter();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($this->admin)->getJson(route('purchase-orders.exchange-rate', ['currency' => 'EUR']))->assertOk();
        }

        $this->actingAs($this->admin)->getJson(route('purchase-orders.exchange-rate', ['currency' => 'EUR']))->assertStatus(429);

        // Another user of the same organization has their own allowance.
        $this->actingAs($this->user($this->org))->getJson(route('purchase-orders.exchange-rate', ['currency' => 'EUR']))->assertOk();
    }

    public function test_an_unknown_code_or_out_of_range_date_never_reaches_the_provider_from_any_po_surface(): void
    {
        // The REST API, GraphQL and MCP create and update drafts through
        // PurchaseOrderService, which looks the rate up.
        $unknown = $this->draft('XYZ');
        $ancient = $this->draft('EUR', '1900-01-01');

        Http::assertNothingSent();
        $this->assertNull($unknown->fresh()->exchange_rate);
        $this->assertNull($ancient->fresh()->exchange_rate);

        $quote = app(ExchangeRateService::class)->quote($this->org->id, 'QQQ');
        $this->assertSame(RateQuote::UNAVAILABLE, $quote->status);
        $this->assertSame(RateQuote::ERROR_UNSUPPORTED, $quote->error);
        Http::assertNothingSent();
    }

    public function test_a_code_the_provider_does_not_list_is_not_asked_for(): void
    {
        Http::fake(['api.frankfurter.dev/*/currencies' => Http::response(['EUR' => 'Euro', 'HUF' => 'Forint'])]);
        $rates = app(ExchangeRateService::class);
        $rates->supportedCurrencies(FrankfurterProvider::ID, ExchangeRateSetting::forOrganization($this->org->id));

        $quote = $rates->quote($this->org->id, 'BRL');

        $this->assertSame(RateQuote::ERROR_UNSUPPORTED, $quote->error);
        Http::assertSentCount(1);
    }

    public function test_a_request_the_provider_refused_is_not_asked_again(): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(['message' => 'not found'], 404)]);
        $rates = app(ExchangeRateService::class);

        $this->assertSame(RateQuote::ERROR_UNSUPPORTED, $rates->quote($this->org->id, 'BRL')->error);
        $this->assertSame(RateQuote::ERROR_UNSUPPORTED, $rates->quote($this->org->id, 'BRL')->error);
        $this->assertSame(RateQuote::ERROR_UNSUPPORTED, $rates->quote($this->org->id, 'BRL')->error);

        Http::assertSentCount(1);
    }

    // 2. Unexpected provider exceptions

    public function test_an_unexpected_provider_exception_fails_only_that_provider_and_organization(): void
    {
        $fake = new FakeExchangeRateProvider;
        $fake->failWith = new RuntimeException('Undefined array key "rates"');
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);
        $this->fakeFrankfurter();

        $broken = $this->organization('Broken', 'HUF');
        $this->supplier($broken, 'EUR');
        $this->useProvider($broken, 'acme-fx', ['api_key' => 'k']);

        $fine = $this->organization('Fine', 'HUF');
        $this->supplier($fine, 'EUR');
        $this->useProvider($fine, 'frankfurter');

        $sync = app(ExchangeRateSynchronizer::class);
        $outcomes = $sync->run($sync->due());

        $this->assertSame('failed', $outcomes[$broken->id]['status']);
        $this->assertSame('success', $outcomes[$fine->id]['status']);

        $setting = ExchangeRateSetting::forOrganization($broken->id);
        $this->assertStringContainsString('Undefined array key', $setting->last_sync_error);
        // Recorded like an outage: retried with back-off.
        $this->assertSame(1, (int) $setting->sync_failures);
        $this->assertTrue($setting->next_sync_at->lessThan(now()->addHours(1)));
    }

    public function test_an_unexpected_provider_exception_is_no_rate_on_the_po_form_never_a_500(): void
    {
        $fake = new FakeExchangeRateProvider;
        $fake->failWith = new \TypeError('Plugin bug');
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);
        $this->useProvider($this->org, 'acme-fx', ['api_key' => 'k']);

        $this->actingAs($this->admin)
            ->getJson(route('purchase-orders.exchange-rate', ['currency' => 'EUR']))
            ->assertOk()
            ->assertJsonPath('data.status', RateQuote::UNAVAILABLE)
            ->assertJsonPath('data.error', RateQuote::ERROR_FETCH_FAILED);

        // A draft is still saved, without a rate.
        $po = $this->draft('EUR');
        $this->assertNull($po->fresh()->exchange_rate);
    }

    // 3. Provider error detail

    public function test_po_users_never_see_the_providers_error_detail(): void
    {
        $fake = new FakeExchangeRateProvider;
        $fake->failWith = ExchangeRateException::unavailable('Could not reach https://svc:hunter2@rates.internal:8443/v1/latest?access_key=abc123def456');
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);
        $this->useProvider($this->org, 'acme-fx', ['api_key' => 'k']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('purchase-orders.exchange-rate', ['currency' => 'EUR']))
            ->assertOk()
            ->assertJsonPath('data.error', RateQuote::ERROR_FETCH_FAILED)
            ->assertJsonPath('data.error_detail', null);

        $this->assertStringNotContainsString('rates.internal', $response->getContent());
        $this->assertStringNotContainsString('abc123def456', $response->getContent());

        // The cool-down that serves the next lookups holds no secret either.
        $this->actingAs($this->admin)
            ->getJson(route('purchase-orders.exchange-rate', ['currency' => 'EUR']))
            ->assertJsonPath('data.error_detail', null);
    }

    public function test_the_sync_error_is_redacted_before_it_is_stored_and_shown(): void
    {
        $fake = new FakeExchangeRateProvider;
        $fake->failWith = ExchangeRateException::misconfigured('Rejected https://svc:hunter2@rates.internal:8443/v1/latest?access_key=abc123def456&base=EUR with Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig and api_key=sk_live_0123456789abcdefABCDEF');
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);
        $this->supplier($this->org, 'EUR');
        $this->useProvider($this->org, 'acme-fx', ['api_key' => 'k']);

        $sync = app(ExchangeRateSynchronizer::class);
        $sync->run($sync->due());

        $stored = (string) ExchangeRateSetting::forOrganization($this->org->id)->last_sync_error;
        foreach (['hunter2', 'abc123def456', 'eyJhbGciOiJIUzI1NiJ9', 'sk_live_0123456789abcdefABCDEF', 'base=EUR'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        // Still useful to the administrator: which service failed.
        $this->assertStringContainsString('rates.internal:8443/v1/latest', $stored);
        $this->assertStringContainsString('[redacted]', $stored);

        // A message stored by an earlier version is redacted on display.
        ExchangeRateSetting::forOrganization($this->org->id)->forceFill(['last_sync_error' => 'Failed: https://rates.internal/v1?token=s3cr3tvalue'])->save();

        $this->actingAs($this->admin)
            ->get(route('settings.exchange-rates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.last_sync_error', 'Failed: https://rates.internal/v1?[redacted]'));
    }

    // 4. Shareable providers with per-organization settings

    public function test_a_shareable_plugin_provider_with_settings_stores_rates_per_organization(): void
    {
        $fake = new FakeExchangeRateProvider('acme-fx', shareable: true);
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);

        $other = $this->organization('Other Org', 'HUF');
        $this->useProvider($this->org, 'acme-fx', ['api_key' => 'poisoner']);
        $this->useProvider($other, 'acme-fx', ['api_key' => 'victim']);

        $fake->rates['EUR/HUF'] = '1.00';
        $poisoned = app(ExchangeRateService::class)->quote($this->org->id, 'EUR');
        $this->assertSame(1.0, (float) $poisoned->rate);

        $this->assertSame(0, ExchangeRate::count());
        $this->assertSame(1, OrganizationExchangeRate::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());

        // The other organization never reads the first one's rate.
        $stored = app(ExchangeRateService::class)->quote($other->id, 'EUR', allowFetch: false);
        $this->assertNull($stored->rate);

        // And the settings page does not call it shared.
        $this->assertFalse(ExchangeRateProviderRegistry::isShareable($fake));
        $this->assertTrue(ExchangeRateProviderRegistry::isShareable(new FrankfurterProvider));
        $this->actingAs($this->admin)
            ->get(route('settings.exchange-rates.index'))
            ->assertInertia(fn ($page) => $page->where('providers', fn ($providers) => collect($providers)->firstWhere('id', 'acme-fx')['capabilities']['shareable'] === false));
    }

    public function test_the_synchronizer_also_stores_them_per_organization(): void
    {
        $fake = new FakeExchangeRateProvider('acme-fx', shareable: true);
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', $fake);

        $other = $this->organization('Other Org', 'HUF');
        $this->supplier($other, 'EUR');
        $this->useProvider($this->org, 'acme-fx', ['api_key' => 'one']);
        $this->useProvider($other, 'acme-fx', ['api_key' => 'two']);

        $sync = app(ExchangeRateSynchronizer::class);
        $sync->run($sync->due());

        $this->assertCount(2, $fake->calls);
        $this->assertSame(0, ExchangeRate::count());
        $this->assertSame(2, OrganizationExchangeRate::withoutGlobalScopes()->count());
    }
}
