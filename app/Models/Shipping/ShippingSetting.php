<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An organization's shipping configuration. The EasyPost API key and webhook
 * secret use the `encrypted` cast, so they are encrypted at rest with the app
 * key, and they are never serialized to a client ($hidden).
 *
 * @property int $id
 * @property int $organization_id
 * @property bool $easypost_enabled
 * @property string|null $easypost_api_key
 * @property bool $easypost_test_mode
 * @property string|null $easypost_webhook_secret
 * @property string $webhook_token
 * @property int|null $default_warehouse_id
 * @property array|null $from_address
 * @property array|null $default_parcel
 * @property bool $notify_customers
 */
class ShippingSetting extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'easypost_enabled',
        'easypost_api_key',
        'easypost_test_mode',
        'easypost_webhook_secret',
        'webhook_token',
        'default_warehouse_id',
        'from_address',
        'default_parcel',
        'notify_customers',
    ];

    protected $hidden = [
        'easypost_api_key',
        'easypost_webhook_secret',
        'webhook_token',
    ];

    protected $attributes = [
        'easypost_enabled' => false,
        'easypost_test_mode' => true,
        'notify_customers' => false,
    ];

    protected function casts(): array
    {
        return [
            'easypost_enabled' => 'boolean',
            'easypost_api_key' => 'encrypted',
            'easypost_test_mode' => 'boolean',
            'easypost_webhook_secret' => 'encrypted',
            'from_address' => 'array',
            'default_parcel' => 'array',
            'notify_customers' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $setting): void {
            $setting->webhook_token ??= self::newWebhookToken();
        });
    }

    public static function newWebhookToken(): string
    {
        return Str::random(48);
    }

    /**
     * The organization's settings row, created with defaults on first use.
     */
    public static function forOrganization(int $organizationId): self
    {
        return static::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(['organization_id' => $organizationId]);
    }

    /**
     * Whether EasyPost is switched on and has a key to call it with.
     */
    public function easyPostConfigured(): bool
    {
        return $this->easypost_enabled && filled($this->easypost_api_key);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }
}
