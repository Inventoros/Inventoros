<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Auth\Organization;
use App\Models\ExchangeRates\ExchangeRateSetting;
use App\Models\ExchangeRates\OrganizationExchangeRate;
use App\Models\Inventory\Supplier;
use App\Services\ExchangeRates\ExchangeRateProvider;
use App\Services\ExchangeRates\ExchangeRateProviderRegistry;
use App\Services\ExchangeRates\ExchangeRateService;
use App\Services\ExchangeRates\ExchangeRateSynchronizer;
use App\Services\ExchangeRates\ProviderConfigField;
use App\Services\ExchangeRates\ProviderErrorMessage;
use App\Services\ExchangeRates\Providers\ManualProvider;
use App\Support\ExchangeMath;
use App\Support\OrganizationCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Exchange rates: the provider and an optional fallback, provider
 * settings (secrets are encrypted at rest, never sent back to the browser;
 * a blank field on save keeps the stored value), automatic synchronization,
 * the current rates with their age, the last sync outcome, and manual rates.
 * Everything here belongs to the signed-in user's organization.
 */
class ExchangeRateSettingsController extends Controller
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateSynchronizer $synchronizer,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;
        $organization = Organization::findOrFail($organizationId);
        $settings = ExchangeRateSetting::forOrganization($organizationId);
        $registry = $this->rates->providers();
        $baseCurrency = $organization->baseCurrency();
        $today = OrganizationCalendar::today($organization);
        $tracked = $this->synchronizer->currenciesFor($settings);

        $providers = collect($registry->all())
            ->map(fn (ExchangeRateProvider $provider) => [
                'id' => $provider->id(),
                'name' => $provider->name(),
                'description' => $provider->description(),
                'plugin' => $registry->pluginFor($provider->id()),
                // Shareable as core applies it: never for a provider with settings.
                'capabilities' => ExchangeRateProviderRegistry::effectiveCapabilities($provider)->toArray(),
                'fields' => collect($provider->configFields())
                    ->map(fn (ProviderConfigField $field) => $field->toArray() + [
                        // Secrets: only whether one is stored, never the value.
                        'value' => $field->isSecret() ? '' : (string) ($settings->configFor($provider->id())[$field->key] ?? ''),
                        'is_set' => filled($settings->configFor($provider->id())[$field->key] ?? null),
                    ])->values()->all(),
            ])->values()->all();

        $rateRows = [];
        foreach (array_filter([$settings->provider, $settings->fallback_provider]) as $providerId) {
            foreach ($this->rates->latestStored($organizationId, $providerId, $tracked) as $currency => $row) {
                $age = $row === null ? null : (int) $row->effective_date->copy()->startOfDay()->diffInDays($today);
                $rateRows[] = [
                    'currency' => $currency,
                    'provider' => $providerId,
                    'provider_name' => $registry->nameFor($providerId),
                    'is_fallback' => $providerId !== $settings->provider,
                    'rate' => $row === null ? null : ExchangeMath::rate((string) $row->rate),
                    'effective_date' => $row?->effective_date->toDateString(),
                    'retrieved_at' => $row?->retrieved_at?->toIso8601String(),
                    'age_days' => $age,
                    'stale' => $age !== null && $age > (int) $settings->stale_after_days,
                ];
            }
        }

        return Inertia::render('Settings/ExchangeRates', [
            'settings' => [
                'provider' => $settings->provider,
                'fallback_provider' => $settings->fallback_provider,
                'currencies' => $settings->configuredCurrencies(),
                'stale_after_days' => (int) $settings->stale_after_days,
                'sync_enabled' => (bool) $settings->sync_enabled,
                'sync_frequency' => $settings->sync_frequency,
                'last_synced_at' => $settings->last_synced_at?->toIso8601String(),
                'last_sync_attempt_at' => $settings->last_sync_attempt_at?->toIso8601String(),
                'last_sync_status' => $settings->last_sync_status,
                // Redacted again on display: rows stored by earlier versions
                // may hold a provider URL or key.
                'last_sync_error' => $settings->last_sync_error === null ? null : ProviderErrorMessage::redact($settings->last_sync_error),
                'sync_failures' => (int) $settings->sync_failures,
                'next_sync_at' => $settings->next_sync_at?->toIso8601String(),
            ],
            'providerAvailable' => $registry->has($settings->provider),
            'fallbackAvailable' => $settings->fallback_provider === null || $registry->has($settings->fallback_provider),
            'providers' => $providers,
            'organizationCurrency' => $baseCurrency,
            'trackedCurrencies' => $tracked,
            'supplierCurrencies' => Supplier::where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereNotNull('currency')
                ->distinct()
                ->pluck('currency')
                ->map(fn ($code) => strtoupper((string) $code))
                ->reject(fn (string $code) => $code === $baseCurrency)
                ->values()
                ->all(),
            'currencyOptions' => array_values(array_diff(array_keys((array) config('currencies.supported', [])), [$baseCurrency])),
            'providerCurrencies' => $registry->has($settings->provider) ? $this->rates->supportedCurrencies($settings->provider, $settings) : null,
            'frequencies' => array_keys((array) config('exchange_rates.frequencies', [])),
            'rates' => $rateRows,
            'manualRates' => OrganizationExchangeRate::where('organization_id', $organizationId)
                ->where('provider', ManualProvider::ID)
                ->with('enteredBy:id,name')
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (OrganizationExchangeRate $rate) => [
                    'id' => $rate->id,
                    'currency' => $rate->base_currency,
                    'quote_currency' => $rate->quote_currency,
                    'rate' => ExchangeMath::rate((string) $rate->rate),
                    'effective_date' => $rate->effective_date->toDateString(),
                    'entered_by' => $rate->enteredBy?->name,
                ])->all(),
            'today' => $today->toDateString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $settings = ExchangeRateSetting::forOrganization($organizationId);
        $registry = $this->rates->providers();
        $providerIds = array_keys($registry->all());
        $baseCurrency = Organization::currencyFor($organizationId);

        $rules = [
            'provider' => ['required', 'string', Rule::in($providerIds)],
            'fallback_provider' => ['nullable', 'string', Rule::in($providerIds), 'different:provider'],
            'currencies' => ['nullable', 'array', 'max:50'],
            'currencies.*' => ['string', Rule::in(array_keys((array) config('currencies.supported', []))), Rule::notIn([$baseCurrency])],
            'stale_after_days' => ['required', 'integer', 'min:1', 'max:60'],
            'sync_enabled' => ['required', 'boolean'],
            'sync_frequency' => ['required', 'string', Rule::in(array_keys((array) config('exchange_rates.frequencies', [])))],
            'config' => ['nullable', 'array'],
        ];

        foreach ($registry->all() as $provider) {
            foreach ($provider->configFields() as $field) {
                $rules["config.{$provider->id()}.{$field->key}"] = $field->rules();
            }
        }

        $validator = validator($request->all(), $rules, [
            'fallback_provider.different' => __('The fallback provider must differ from the main provider.'),
        ]);

        // A required setting (an API key) must be submitted now or already
        // stored for the providers that will be used.
        $validator->after(function (Validator $v) use ($request, $registry, $settings) {
            foreach (array_filter([$request->input('provider'), $request->input('fallback_provider')]) as $providerId) {
                foreach ($registry->get((string) $providerId)?->configFields() ?? [] as $field) {
                    $submitted = $request->input("config.{$providerId}.{$field->key}");
                    if ($field->required && blank($submitted) && blank($settings->configFor((string) $providerId)[$field->key] ?? null)) {
                        $v->errors()->add("config.{$providerId}.{$field->key}", __('Enter :field for this provider.', ['field' => $field->label]));
                    }
                }
            }
        });

        $validated = $validator->validate();

        // Merge the submitted provider settings into the stored ones: a blank
        // secret keeps the stored secret, a blank text field clears it.
        $config = (array) ($settings->provider_config ?? []);
        foreach ($registry->all() as $provider) {
            $submitted = (array) ($validated['config'][$provider->id()] ?? []);
            foreach ($provider->configFields() as $field) {
                if (! array_key_exists($field->key, $submitted)) {
                    continue;
                }

                $value = trim((string) ($submitted[$field->key] ?? ''));

                if ($field->isSecret() && $value === '') {
                    continue;
                }

                if ($value === '') {
                    unset($config[$provider->id()][$field->key]);
                } else {
                    $config[$provider->id()][$field->key] = $value;
                }
            }
        }

        $enabling = (bool) $validated['sync_enabled'] && (! $settings->sync_enabled || $settings->sync_frequency !== $validated['sync_frequency']);

        $settings->fill([
            'provider' => $validated['provider'],
            'fallback_provider' => $validated['fallback_provider'] ?? null,
            'provider_config' => $config === [] ? null : $config,
            'currencies' => array_values(array_unique(array_map('strtoupper', $validated['currencies'] ?? []))),
            'stale_after_days' => (int) $validated['stale_after_days'],
            'sync_enabled' => (bool) $validated['sync_enabled'],
            'sync_frequency' => $validated['sync_frequency'],
        ]);

        if ($enabling) {
            // Due on the next scheduled run.
            $settings->next_sync_at = null;
            $settings->sync_failures = 0;
        }

        $settings->save();

        return redirect()->back()->with('success', __('Exchange rate settings saved.'));
    }

    /**
     * Fetch the latest rates for this organization now.
     */
    public function sync(Request $request): RedirectResponse
    {
        $settings = ExchangeRateSetting::forOrganization((int) $request->user()->organization_id);
        $outcome = $this->synchronizer->run([$settings])[(int) $settings->organization_id];

        return match ($outcome['status']) {
            ExchangeRateSetting::STATUS_SUCCESS => redirect()->back()->with('success', __('Exchange rates refreshed.')),
            ExchangeRateSetting::STATUS_PARTIAL => redirect()->back()->with('warning', __('Some exchange rates could not be refreshed. See the synchronization status for details.')),
            default => redirect()->back()->with('error', __('Exchange rates could not be refreshed. See the synchronization status for details.')),
        };
    }

    /**
     * Enter a manual rate (used when "Manual rates" is the provider or the
     * fallback).
     */
    public function storeRate(Request $request): RedirectResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $baseCurrency = Organization::currencyFor($organizationId);
        $today = OrganizationCalendar::today(Organization::find($organizationId))->toDateString();

        $validated = $request->validate([
            'currency' => ['required', 'string', Rule::in(array_keys((array) config('currencies.supported', []))), Rule::notIn([$baseCurrency])],
            // isValidRate: the rate math rejects forms the numeric rule
            // accepts (".5", "5.", "+2"), which would otherwise be a 500.
            'rate' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,10', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! \App\Support\ExchangeMath::isValidRate($value)) {
                    $fail(__('Enter the rate as a plain number, for example 0.5 or 395.12.'));
                }
            }],
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
        ]);

        $this->rates->storeManual($organizationId, $validated['currency'], (string) $validated['rate'], CarbonImmutable::parse($validated['effective_date']), $request->user());

        return redirect()->back()->with('success', __('Manual exchange rate saved.'));
    }

    public function destroyRate(Request $request, int $rate): RedirectResponse
    {
        OrganizationExchangeRate::where('organization_id', (int) $request->user()->organization_id)
            ->where('provider', ManualProvider::ID)
            ->findOrFail($rate)
            ->delete();

        return redirect()->back()->with('success', __('Manual exchange rate deleted.'));
    }
}
