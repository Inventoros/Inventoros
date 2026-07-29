<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service for managing organization-specific settings.
 *
 * Provides cached access to settings stored in the database,
 * with support for encrypted values and email configuration.
 */
final class SettingsService
{
    public const CACHE_TTL_SECONDS = 3600;

    public const DEFAULT_SMTP_PORT = 587;

    /**
     * Get a setting value for current organization.
     *
     * @param  string  $key  The setting key to retrieve
     * @param  mixed  $default  Default value if setting not found
     * @return mixed The setting value or default
     *
     * @throws RuntimeException If no authenticated user with organization
     */
    public static function get(string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        // Resolve the organization explicitly when given (queue/console callers
        // that run outside a request), falling back to the authenticated user.
        // Without this a background job — e.g. a product import that pushes
        // stock across the low-stock threshold and fires a notification — throws
        // "User must be authenticated" and fails.
        $organizationId ??= auth()->user()?->organization_id;
        if (! $organizationId) {
            throw new RuntimeException('User must be authenticated to access settings');
        }

        $cacheKey = "settings.{$organizationId}.{$key}";

        // Cache-first: previously the row was queried BEFORE Cache::remember, so
        // the cache never actually saved a query. Serve non-encrypted settings
        // straight from cache when present.
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $setting = Setting::where('organization_id', $organizationId)
            ->where('key', $key)
            ->first();

        if (! $setting) {
            return $default;
        }

        // Don't cache encrypted settings for security.
        if ($setting->encrypted) {
            try {
                return Crypt::decryptString($setting->value);
            } catch (DecryptException $e) {
                // Settings written before the encryption-order bug fix were
                // persisted as plaintext while still flagged encrypted=true.
                // Surface the issue rather than silently returning a half-truth.
                Log::warning('Failed to decrypt setting; treating as default', [
                    'organization_id' => $organizationId,
                    'key' => $key,
                ]);

                return $default;
            }
        }

        Cache::put($cacheKey, $setting->value, 3600);

        return $setting->value;
    }

    /**
     * Set a setting value.
     *
     * @param  string  $key  The setting key to set
     * @param  mixed  $value  The value to store
     * @param  bool  $encrypted  Whether to encrypt the value (default: false)
     *
     * @throws RuntimeException If no authenticated user with organization
     */
    public static function set(string $key, $value, bool $encrypted = false): void
    {
        $organizationId = auth()->user()?->organization_id;
        if (! $organizationId) {
            throw new RuntimeException('User must be authenticated to access settings');
        }

        Setting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'key' => $key,
            ],
            [
                'encrypted' => $encrypted,
                'value' => $encrypted ? Crypt::encryptString((string) $value) : $value,
            ]
        );

        Cache::forget("settings.{$organizationId}.{$key}");
    }

    /**
     * Get all email settings as array.
     *
     * @return array{provider: string, from_address: string|null, from_name: string|null, smtp: array, mailgun: array, sendgrid: array} Email configuration array
     */
    public static function getEmailConfig(?int $organizationId = null): array
    {
        return [
            'provider' => self::get('email.provider', 'smtp', $organizationId),
            'from_address' => self::get('email.from_address', null, $organizationId),
            'from_name' => self::get('email.from_name', null, $organizationId),
            'smtp' => [
                'host' => self::get('email.smtp.host', null, $organizationId),
                'port' => self::get('email.smtp.port', 587, $organizationId),
                'username' => self::get('email.smtp.username', null, $organizationId),
                'password' => self::get('email.smtp.password', null, $organizationId),
                'encryption' => self::get('email.smtp.encryption', 'tls', $organizationId),
            ],
            'mailgun' => [
                'domain' => self::get('email.mailgun.domain', null, $organizationId),
                'secret' => self::get('email.mailgun.secret', null, $organizationId),
            ],
            'sendgrid' => [
                'api_key' => self::get('email.sendgrid.api_key', null, $organizationId),
            ],
        ];
    }

    /**
     * Apply email configuration to Laravel's mail config.
     *
     * Configures mail driver, from address, and provider-specific settings
     * based on stored organization settings.
     */
    public static function applyEmailConfig(?int $organizationId = null): void
    {
        $config = self::getEmailConfig($organizationId);

        $organizationId ??= auth()->user()?->organization_id;

        // Validate critical configuration
        if (empty($config['from_address'])) {
            Log::warning('Email configuration missing critical field: from_address', [
                'organization_id' => $organizationId,
            ]);
        }
        if (empty($config['provider'])) {
            Log::warning('Email configuration missing critical field: provider', [
                'organization_id' => $organizationId,
            ]);
        }

        Config::set('mail.default', $config['provider']);
        Config::set('mail.from.address', $config['from_address']);
        Config::set('mail.from.name', $config['from_name']);

        switch ($config['provider']) {
            case 'smtp':
                Config::set('mail.mailers.smtp.host', $config['smtp']['host']);
                Config::set('mail.mailers.smtp.port', $config['smtp']['port']);
                Config::set('mail.mailers.smtp.username', $config['smtp']['username']);
                Config::set('mail.mailers.smtp.password', $config['smtp']['password']);
                Config::set('mail.mailers.smtp.encryption', $config['smtp']['encryption']);
                break;

            case 'mailgun':
                Config::set('services.mailgun.domain', $config['mailgun']['domain']);
                Config::set('services.mailgun.secret', $config['mailgun']['secret']);
                break;

            case 'sendgrid':
                Config::set('services.sendgrid.api_key', $config['sendgrid']['api_key']);
                break;
        }
    }
}
