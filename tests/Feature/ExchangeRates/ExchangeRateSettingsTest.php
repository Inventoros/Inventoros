<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Enums\Permission;
use App\Models\ExchangeRates\ExchangeRate;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\ExchangeRates\OrganizationExchangeRate;
use App\Models\PermissionSet;
use App\Models\Role;
use App\Services\ExchangeRates\ExchangeRateProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Settings > Exchange rates: who may open it, provider choice and settings
 * (secrets encrypted, never sent back), validation, refresh now, manual
 * rates, and that everything stays inside the organization.
 */
final class ExchangeRateSettingsTest extends TestCase
{
    use ExchangeRateFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->atTime();
        Http::preventStrayRequests();
        // The page asks the provider which currencies it offers.
        Http::fake(['api.frankfurter.dev/v1/currencies' => Http::response(['EUR' => 'Euro', 'HUF' => 'Hungarian Forint', 'USD' => 'US Dollar'])]);
    }

    protected function tearDown(): void
    {
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'provider' => 'frankfurter',
            'fallback_provider' => null,
            'currencies' => ['USD'],
            'stale_after_days' => 5,
            'sync_enabled' => true,
            'sync_frequency' => 'every_six_hours',
            'config' => [],
        ];
    }

    public function test_only_users_with_manage_exchange_rates_can_open_or_change_it(): void
    {
        $org = $this->organization();
        $member = $this->user($org, 'member', ['view_settings', 'manage_organization']);
        $manager = $this->user($org, 'member', ['manage_exchange_rates']);

        $this->actingAs($member)->get(route('settings.exchange-rates.index'))->assertForbidden();
        $this->actingAs($member)->patch(route('settings.exchange-rates.update'), $this->form())->assertForbidden();
        $this->actingAs($member)->post(route('settings.exchange-rates.sync'))->assertForbidden();
        $this->actingAs($member)->post(route('settings.exchange-rates.rates.store'), ['currency' => 'EUR', 'rate' => '1', 'effective_date' => '2026-11-16'])->assertForbidden();

        $this->actingAs($manager)->get(route('settings.exchange-rates.index'))->assertOk();
        $this->actingAs($this->user($org))->get(route('settings.exchange-rates.index'))->assertOk();
    }

    public function test_the_page_shows_providers_tracked_currencies_rates_and_status(): void
    {
        $org = $this->organization();
        $this->supplier($org, 'EUR');
        ExchangeRate::create(['provider' => 'frankfurter', 'base_currency' => 'EUR', 'quote_currency' => 'HUF', 'rate' => '361.5', 'effective_date' => '2026-11-06', 'retrieved_at' => now()->subDays(10)]);

        $this->actingAs($this->user($org))
            ->get(route('settings.exchange-rates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/ExchangeRates')
                ->where('organizationCurrency', 'HUF')
                ->where('settings.provider', 'frankfurter')
                ->where('settings.sync_enabled', false)
                ->where('providerAvailable', true)
                ->where('trackedCurrencies', ['EUR'])
                ->where('supplierCurrencies', ['EUR'])
                ->where('providerCurrencies', ['EUR', 'HUF', 'USD'])
                ->where('rates.0.currency', 'EUR')
                ->where('rates.0.rate', '361.5')
                ->where('rates.0.stale', true)
                ->where('rates.0.age_days', 10)
                ->where('providers', fn ($providers) => collect($providers)->pluck('id')->all() === ['frankfurter', 'manual']));
    }

    public function test_settings_are_saved_and_a_plugin_providers_secret_is_encrypted_and_never_sent_back(): void
    {
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', new FakeExchangeRateProvider);
        $org = $this->organization();
        $admin = $this->user($org);

        $this->actingAs($admin)
            ->patch(route('settings.exchange-rates.update'), $this->form([
                'provider' => 'acme-fx',
                'fallback_provider' => 'frankfurter',
                'config' => ['acme-fx' => ['api_key' => 'sk_live_12345', 'account' => 'ACME-1']],
            ]))
            ->assertSessionHasNoErrors();

        $settings = ExchangeRateSetting::forOrganization($org->id);
        $this->assertSame('acme-fx', $settings->provider);
        $this->assertSame('frankfurter', $settings->fallback_provider);
        $this->assertSame(['USD'], $settings->currencies);
        $this->assertSame(5, $settings->stale_after_days);
        $this->assertTrue($settings->sync_enabled);
        $this->assertSame('every_six_hours', $settings->sync_frequency);
        $this->assertSame(['api_key' => 'sk_live_12345', 'account' => 'ACME-1'], $settings->configFor('acme-fx'));

        $raw = (string) DB::table('exchange_rate_settings')->where('organization_id', $org->id)->value('provider_config');
        $this->assertStringNotContainsString('sk_live_12345', $raw, 'Provider settings are encrypted at rest.');

        $response = $this->actingAs($admin)->get(route('settings.exchange-rates.index'));
        $this->assertStringNotContainsString('sk_live_12345', $response->getContent());
        $response->assertInertia(fn ($page) => $page->where('providers', function ($providers) {
            $fields = collect(collect($providers)->firstWhere('id', 'acme-fx')['fields'])->keyBy('key');

            return $fields['api_key']['value'] === '' && $fields['api_key']['is_set'] === true && $fields['account']['value'] === 'ACME-1';
        }));

        // A blank secret keeps the stored one; a blank text field clears it.
        $this->actingAs($admin)
            ->patch(route('settings.exchange-rates.update'), $this->form([
                'provider' => 'acme-fx',
                'config' => ['acme-fx' => ['api_key' => '', 'account' => '']],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['api_key' => 'sk_live_12345'], ExchangeRateSetting::forOrganization($org->id)->configFor('acme-fx'));
    }

    public function test_input_is_validated(): void
    {
        app(ExchangeRateProviderRegistry::class)->register('acme-fx', new FakeExchangeRateProvider);
        $org = $this->organization();
        $admin = $this->user($org);

        $this->actingAs($admin)->patch(route('settings.exchange-rates.update'), $this->form(['provider' => 'nope']))->assertSessionHasErrors('provider');
        $this->actingAs($admin)->patch(route('settings.exchange-rates.update'), $this->form(['fallback_provider' => 'frankfurter']))->assertSessionHasErrors('fallback_provider');
        $this->actingAs($admin)->patch(route('settings.exchange-rates.update'), $this->form(['currencies' => ['HUF']]))->assertSessionHasErrors('currencies.0');
        $this->actingAs($admin)->patch(route('settings.exchange-rates.update'), $this->form(['currencies' => ['XXX']]))->assertSessionHasErrors('currencies.0');
        $this->actingAs($admin)->patch(route('settings.exchange-rates.update'), $this->form(['stale_after_days' => 0]))->assertSessionHasErrors('stale_after_days');
        $this->actingAs($admin)->patch(route('settings.exchange-rates.update'), $this->form(['sync_frequency' => 'every_minute']))->assertSessionHasErrors('sync_frequency');
        // A required key, neither submitted nor stored.
        $this->actingAs($admin)->patch(route('settings.exchange-rates.update'), $this->form(['provider' => 'acme-fx']))->assertSessionHasErrors('config.acme-fx.api_key');

        $this->assertSame('frankfurter', ExchangeRateSetting::forOrganization($org->id)->provider);
    }

    public function test_turning_sync_on_makes_it_due_at_the_next_run(): void
    {
        $org = $this->organization();
        $settings = ExchangeRateSetting::forOrganization($org->id);
        $settings->forceFill(['next_sync_at' => now()->addDays(3), 'sync_failures' => 2])->save();

        $this->actingAs($this->user($org))->patch(route('settings.exchange-rates.update'), $this->form())->assertSessionHasNoErrors();

        $fresh = $settings->fresh();
        $this->assertNull($fresh->next_sync_at);
        $this->assertSame(0, $fresh->sync_failures);
    }

    public function test_refresh_now_fetches_and_reports(): void
    {
        $org = $this->organization();
        $this->supplier($org, 'EUR');
        $admin = $this->user($org);
        $this->fakeFrankfurter();

        $this->actingAs($admin)->post(route('settings.exchange-rates.sync'))->assertSessionHas('success');

        $this->assertSame(1, ExchangeRate::count());
        $this->assertSame('success', ExchangeRateSetting::forOrganization($org->id)->last_sync_status);
    }

    public function test_a_failed_refresh_is_reported_without_an_error_page(): void
    {
        $org = $this->organization();
        $this->supplier($org, 'EUR');
        Http::fake(['api.frankfurter.dev/v1/latest*' => Http::response('down', 503)]);

        $this->actingAs($this->user($org))->post(route('settings.exchange-rates.sync'))->assertSessionHas('error');

        $this->assertSame('failed', ExchangeRateSetting::forOrganization($org->id)->last_sync_status);
    }

    public function test_manual_rates_can_be_added_and_deleted_inside_the_organization_only(): void
    {
        $org = $this->organization();
        $admin = $this->user($org);

        $this->actingAs($admin)
            ->post(route('settings.exchange-rates.rates.store'), ['currency' => 'EUR', 'rate' => '362.4', 'effective_date' => '2026-11-15'])
            ->assertSessionHasNoErrors();

        $rate = OrganizationExchangeRate::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($org->id, $rate->organization_id);
        $this->assertSame('manual', $rate->provider);
        $this->assertSame('HUF', $rate->quote_currency);
        $this->assertSame($admin->id, $rate->entered_by);

        foreach ([
            ['currency' => 'HUF', 'rate' => '1', 'effective_date' => '2026-11-15'],
            ['currency' => 'EUR', 'rate' => '0', 'effective_date' => '2026-11-15'],
            ['currency' => 'EUR', 'rate' => '360', 'effective_date' => '2026-12-01'],
            // Pass the numeric rule but not the rate math: no longer a 500.
            ['currency' => 'EUR', 'rate' => '.5', 'effective_date' => '2026-11-15'],
            ['currency' => 'EUR', 'rate' => '+2', 'effective_date' => '2026-11-15'],
        ] as $bad) {
            $this->actingAs($admin)->post(route('settings.exchange-rates.rates.store'), $bad)->assertSessionHasErrors();
        }

        $other = $this->organization('Other');
        $this->actingAs($this->user($other))
            ->delete(route('settings.exchange-rates.rates.destroy', $rate->id))
            ->assertNotFound();
        $this->actingAs($this->user($other))
            ->get(route('settings.exchange-rates.index'))
            ->assertInertia(fn ($page) => $page->where('manualRates', []));

        $this->actingAs($admin)->delete(route('settings.exchange-rates.rates.destroy', $rate->id))->assertSessionHas('success');
        $this->assertSame(0, OrganizationExchangeRate::withoutGlobalScopes()->count());
    }

    public function test_settings_are_per_organization(): void
    {
        $a = $this->organization('A');
        $b = $this->organization('B');

        $this->actingAs($this->user($a))->patch(route('settings.exchange-rates.update'), $this->form(['provider' => 'manual']))->assertSessionHasNoErrors();

        $this->assertSame('manual', ExchangeRateSetting::forOrganization($a->id)->provider);
        $this->assertSame('frankfurter', ExchangeRateSetting::forOrganization($b->id)->provider);
        $this->actingAs($this->user($b))
            ->get(route('settings.exchange-rates.index'))
            ->assertInertia(fn ($page) => $page->where('settings.provider', 'frankfurter'));
    }

    public function test_the_new_permissions_are_granted_like_the_existing_ones(): void
    {
        $this->assertSame('Settings', Permission::MANAGE_EXCHANGE_RATES->category());
        $this->assertSame('Purchase Order Management', Permission::OVERRIDE_PURCHASE_ORDER_EXCHANGE_RATE->category());

        $template = collect(PermissionSet::getDefaultTemplates())->firstWhere('slug', 'purchasing-agent');
        $this->assertContains('override_purchase_order_exchange_rate', $template['permissions']);

        // The upgrade migration on an install seeded before these existed.
        $admin = Role::create(['name' => 'Administrator', 'slug' => 'system-administrator', 'is_system' => true, 'permissions' => ['view_products']]);
        DB::table('permission_sets')->insert(['name' => 'Purchasing Agent', 'slug' => 'purchasing-agent', 'permissions' => json_encode(['view_purchase_orders']), 'is_template' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2026_11_16_000006_grant_exchange_rate_permissions.php');
        $migration->up();

        $this->assertEqualsCanonicalizing(['view_products', 'manage_exchange_rates', 'override_purchase_order_exchange_rate'], $admin->fresh()->permissions);
        $this->assertEqualsCanonicalizing(['view_purchase_orders', 'override_purchase_order_exchange_rate'], json_decode((string) DB::table('permission_sets')->where('slug', 'purchasing-agent')->value('permissions'), true));

        $migration->down();
        $this->assertSame(['view_products'], $admin->fresh()->permissions);
    }
}
