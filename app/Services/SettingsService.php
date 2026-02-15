<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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
     * @param string $key The setting key to retrieve
     * @param mixed $default Default value if setting not found
     * @return mixed The setting value or default
     * @throws RuntimeException If no authenticated user with organization
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $organizationId = auth()->user()?->organization_id;
        if (!$organizationId) {
            throw new RuntimeException('User must be authenticated to access settings');
        }

        // First check if this is an encrypted setting - if so, skip cache
        $setting = Setting::where('organization_id', $organizationId)
            ->where('key', $key)
            ->first();

        if (!$setting) {
            return $default;
        }

        // Don't cache encrypted settings for security
        if ($setting->encrypted) {
            return $setting->value;
        }

        // For non-encrypted settings, use cache
        return Cache::remember("settings.{$organizationId}.{$key}", 3600, function () use ($setting) {
            return $setting->value;
        });
    }

    /**
     * Set a setting value.
     *
     * @param string $key The setting key to set
     * @param mixed $value The value to store
     * @param bool $encrypted Whether to encrypt the value (default: false)
     * @return void
     * @throws RuntimeException If no authenticated user with organization
     */
    public static function set(string $key, $value, bool $encrypted = false): void
    {
        $organizationId = auth()->user()?->organization_id;
        if (!$organizationId) {
            throw new RuntimeException('User must be authenticated to access settings');
        }

        Setting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'key' => $key
            ],
            [
                'value' => $value,
                'encrypted' => $encrypted
            ]
        );

        Cache::forget("settings.{$organizationId}.{$key}");
    }

    /**
     * Get all email settings as array.
     *
     * @return array{provider: string, from_address: string|null, from_name: string|null, smtp: array, mailgun: array, sendgrid: array} Email configuration array
     */
    public static function getEmailConfig(): array
    {
        return [
            'provider' => self::get('email.provider', 'smtp'),
            'from_address' => self::get('email.from_address'),
            'from_name' => self::get('email.from_name'),
            'smtp' => [
                'host' => self::get('email.smtp.host'),
                'port' => self::get('email.smtp.port', 587),
                'username' => self::get('email.smtp.username'),
                'password' => self::get('email.smtp.password'),
                'encryption' => self::get('email.smtp.encryption', 'tls'),
            ],
            'mailgun' => [
                'domain' => self::get('email.mailgun.domain'),
                'secret' => self::get('email.mailgun.secret'),
            ],
            'sendgrid' => [
                'api_key' => self::get('email.sendgrid.api_key'),
            ],
        ];
    }

    /**
     * Apply email configuration to Laravel's mail config.
     *
     * Configures mail driver, from address, and provider-specific settings
     * based on stored organization settings.
     *
     * @return void
     */
    public static function applyEmailConfig(): void
    {
        $config = self::getEmailConfig();

        // Validate critical configuration
        if (empty($config['from_address'])) {
            Log::warning('Email configuration missing critical field: from_address', [
                'organization_id' => auth()->user()?->organization_id,
            ]);
        }
        if (empty($config['provider'])) {
            Log::warning('Email configuration missing critical field: provider', [
                'organization_id' => auth()->user()?->organization_id,
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
