<?php

declare(strict_types=1);

namespace App\Models\ExchangeRates;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An organization's exchange rate configuration.
 *
 * provider_config holds each provider's settings keyed by provider id
 * ({"acme-fx": {"api_key": "..."}}). It is encrypted at rest with the app key
 * (`encrypted:array`) and never serialized to a client.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $provider
 * @property string|null $fallback_provider
 * @property array<string, array<string, mixed>>|null $provider_config
 * @property array<int, string>|null $currencies
 * @property int $stale_after_days
 * @property bool $sync_enabled
 * @property string $sync_frequency
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_sync_attempt_at
 * @property string|null $last_sync_status
 * @property string|null $last_sync_error
 * @property int $sync_failures
 * @property Carbon|null $next_sync_at
 */
class ExchangeRateSetting extends Model
{
    use BelongsToOrganization;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'provider',
        'fallback_provider',
        'provider_config',
        'currencies',
        'stale_after_days',
        'sync_enabled',
        'sync_frequency',
    ];

    protected $hidden = [
        'provider_config',
    ];

    protected function casts(): array
    {
        return [
            'provider_config' => 'encrypted:array',
            'currencies' => 'array',
            'stale_after_days' => 'integer',
            'sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_sync_attempt_at' => 'datetime',
            'next_sync_at' => 'datetime',
            'sync_failures' => 'integer',
        ];
    }

    /**
     * The organization's settings, created with the defaults on first use.
     */
    public static function forOrganization(int $organizationId): self
    {
        $existing = static::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::query()->withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'provider' => (string) config('exchange_rates.default_provider', 'frankfurter'),
                'stale_after_days' => max(1, (int) config('exchange_rates.stale_after_days', 4)),
                'sync_enabled' => false,
                'sync_frequency' => (string) config('exchange_rates.default_frequency', 'daily'),
            ],
        );
    }

    /**
     * This organization's settings for one provider.
     *
     * @return array<string, mixed>
     */
    public function configFor(string $provider): array
    {
        $config = $this->provider_config[$provider] ?? [];

        return is_array($config) ? $config : [];
    }

    /**
     * The extra currencies an administrator asked to keep rates for.
     *
     * @return array<int, string>
     */
    public function configuredCurrencies(): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($code) => strtoupper(trim((string) $code)), (array) $this->currencies),
            fn (string $code) => preg_match('/^[A-Z]{3}\z/', $code) === 1,
        )));
    }

    /**
     * Minutes between automatic synchronizations.
     */
    public function frequencyMinutes(): int
    {
        $frequencies = (array) config('exchange_rates.frequencies', []);

        return (int) ($frequencies[$this->sync_frequency] ?? $frequencies['daily'] ?? 1440);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
