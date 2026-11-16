<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\ExchangeRates\ExchangeRateProviderRegistry;
use App\Services\ExchangeRates\ExchangeRateService;
use App\Services\ExchangeRates\ExchangeRateSynchronizer;
use App\Services\ExchangeRates\RateQuote;
use App\Services\PluginService;
use App\Services\PurchaseOrderEmailService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * Plugins add exchange rate providers with register_exchange_rate_provider()
 * from their main file, under the same lifecycle as their other extensions:
 * present while the plugin is active, gone once it is not, and an
 * organization or purchase order that named it degrades clearly.
 */
final class ExchangeRatePluginProviderTest extends TestCase
{
    use ExchangeRateFixtures, InteractsWithFixturePlugins, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->atTime();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    private function providerMainFile(string $slug): string
    {
        return <<<PHP
<?php
use App\Services\ExchangeRates\ExchangeRateException;
use App\Services\ExchangeRates\ExchangeRateProvider;
use App\Services\ExchangeRates\FetchedRates;
use App\Services\ExchangeRates\ProviderCapabilities;
use App\Services\ExchangeRates\ProviderConfigField;
use Carbon\CarbonImmutable;

register_exchange_rate_provider('{$slug}', new class implements ExchangeRateProvider {
    public function id(): string { return '{$slug}'; }
    public function name(): string { return 'Fixture Bank'; }
    public function description(): string { return 'Rates from the fixture bank.'; }
    public function capabilities(): ProviderCapabilities { return new ProviderCapabilities(automatic: true, historical: false, shareable: false); }
    public function configFields(): array { return [new ProviderConfigField('api_key', 'API key', 'secret', required: true)]; }
    public function supportedCurrencies(array \$config): array { return ['EUR', 'HUF']; }
    public function fetch(string \$base, array \$symbols, ?CarbonImmutable \$date, array \$config): FetchedRates
    {
        if ((\$config['api_key'] ?? null) !== 'secret-key') {
            throw ExchangeRateException::misconfigured('Bad key');
        }
        return new FetchedRates(\$base, '2026-11-16', array_fill_keys(\$symbols, '367.25'));
    }
});
PHP;
    }

    public function test_core_rules_for_plugin_provider_ids(): void
    {
        $cases = [
            'a slug that is not one' => fn () => register_exchange_rate_provider('Not A Slug', new FakeExchangeRateProvider('acme-fx')),
            'an id outside the slug' => fn () => register_exchange_rate_provider('acme-fx', new FakeExchangeRateProvider('other-fx')),
            'a core id' => fn () => register_exchange_rate_provider('frankfurter', new FakeExchangeRateProvider('frankfurter')),
            'a class that is not a provider' => fn () => register_exchange_rate_provider('acme-fx', \stdClass::class),
        ];

        foreach ($cases as $label => $register) {
            try {
                $register();
                $this->fail("Expected {$label} to be refused.");
            } catch (InvalidArgumentException|\TypeError) {
                $this->assertTrue(true);
            }
        }

        // The slug itself, or "{slug}.something", is accepted.
        register_exchange_rate_provider('acme-fx', new FakeExchangeRateProvider('acme-fx'));
        register_exchange_rate_provider('acme-fx', new FakeExchangeRateProvider('acme-fx.premium'));
        $registry = app(ExchangeRateProviderRegistry::class);
        $this->assertTrue($registry->has('acme-fx'));
        $this->assertTrue($registry->has('acme-fx.premium'));
        $this->assertSame('acme-fx', $registry->pluginFor('acme-fx.premium'));
        $this->assertNull($registry->pluginFor('frankfurter'));
    }

    public function test_a_plugin_provider_follows_the_plugin_lifecycle(): void
    {
        $slug = $this->makeFixturePlugin(files: ['Plugin.php' => $this->providerMainFile('fixture-bank')], slug: 'fixture-bank');
        $plugins = app(PluginService::class);
        $registry = app(ExchangeRateProviderRegistry::class);

        $plugins->activatePlugin($slug);
        $this->assertTrue($registry->has('fixture-bank'));

        $org = $this->organization();
        $admin = $this->user($org);
        $supplier = $this->supplier($org, 'EUR');
        $product = $this->product($org);

        // An administrator picks it and enters its key on the settings page.
        $this->actingAs($admin)
            ->get(route('settings.exchange-rates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('providers', fn ($providers) => collect($providers)->contains(fn ($p) => $p['id'] === 'fixture-bank'
                    && $p['plugin'] === 'fixture-bank'
                    && $p['fields'][0]['key'] === 'api_key')));

        $this->actingAs($admin)
            ->patch(route('settings.exchange-rates.update'), [
                'provider' => 'fixture-bank', 'fallback_provider' => null, 'currencies' => [], 'stale_after_days' => 4,
                'sync_enabled' => false, 'sync_frequency' => 'daily', 'config' => ['fixture-bank' => ['api_key' => 'secret-key']],
            ])
            ->assertSessionHasNoErrors();

        $po = app(PurchaseOrderService::class)->create($org->id, $admin, [
            'supplier_id' => $supplier->id, 'order_date' => '2026-11-16', 'currency' => 'EUR',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => '50.00']],
        ]);
        $this->assertSame('fixture-bank', $po->exchange_rate_provider);
        $this->assertSame('367.2500000000', $po->exchange_rate);
        app(PurchaseOrderEmailService::class)->send($po, $admin);

        // Deactivated: on the next request the plugin is not loaded, so its
        // provider is gone.
        $plugins->deactivatePlugin($slug);
        $registry->clear();
        $plugins->loadActivePlugins();
        $this->assertFalse($registry->has('fixture-bank'));

        $quote = app(ExchangeRateService::class)->quote($org->id, 'EUR');
        $this->assertSame(RateQuote::UNAVAILABLE, $quote->status);
        $this->assertSame(RateQuote::ERROR_PROVIDER_UNAVAILABLE, $quote->error);

        $this->actingAs($admin)
            ->get(route('settings.exchange-rates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('providerAvailable', false)->where('settings.provider', 'fixture-bank'));

        // The PO keeps its rate and says the provider is gone.
        $this->actingAs($admin)
            ->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('exchangeRate.rate', '367.25')
                ->where('exchangeRate.provider', 'fixture-bank')
                ->where('exchangeRate.provider_available', false));

        // A new PO in EUR is still saved, without a rate.
        $draft = app(PurchaseOrderService::class)->create($org->id, $admin, [
            'supplier_id' => $supplier->id, 'order_date' => '2026-11-16', 'currency' => 'EUR',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '5.00']],
        ]);
        $this->assertNull($draft->exchange_rate);

        $setting = ExchangeRateSetting::forOrganization($org->id);
        $setting->update(['sync_enabled' => true]);
        $outcome = app(ExchangeRateSynchronizer::class)->run([$setting->fresh()])[$org->id];
        $this->assertSame('failed', $outcome['status']);
        $this->assertStringContainsString('fixture-bank', (string) $outcome['error']);
        Http::assertNothingSent();
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
    }
}
