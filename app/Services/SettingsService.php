<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    /**
     * Get a setting value for current organization.
     */
    public static function get(string $key, $default = null)
    {
        $organizationId = auth()->user()->organization_id;

        return Cache::remember("settings.{$organizationId}.{$key}", 3600, function () use ($organizationId, $key, $default) {
            $setting = Setting::where('organization_id', $organizationId)
                ->where('key', $key)
                ->first();

            if (!$setting) {
                return $default;
            }

            return $setting->value;
        });
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, $value, bool $encrypted = false): void
    {
        $organizationId = auth()->user()->organization_id;

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
     */
    public static function applyEmailConfig(): void
    {
        $config = self::getEmailConfig();

        \Config::set('mail.default', $config['provider']);
        \Config::set('mail.from.address', $config['from_address']);
        \Config::set('mail.from.name', $config['from_name']);

        switch ($config['provider']) {
            case 'smtp':
                \Config::set('mail.mailers.smtp.host', $config['smtp']['host']);
                \Config::set('mail.mailers.smtp.port', $config['smtp']['port']);
                \Config::set('mail.mailers.smtp.username', $config['smtp']['username']);
                \Config::set('mail.mailers.smtp.password', $config['smtp']['password']);
                \Config::set('mail.mailers.smtp.encryption', $config['smtp']['encryption']);
                break;

            case 'mailgun':
                \Config::set('services.mailgun.domain', $config['mailgun']['domain']);
                \Config::set('services.mailgun.secret', $config['mailgun']['secret']);
                break;

            case 'sendgrid':
                \Config::set('services.sendgrid.api_key', $config['sendgrid']['api_key']);
                break;
        }
    }
}
