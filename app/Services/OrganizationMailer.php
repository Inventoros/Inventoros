<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Resolves the mailer an organization's email goes out through.
 *
 * Each organization gets its own named mailer (`organization_{id}`) built from
 * its stored email settings. The global mail config is never touched: a queue
 * worker delivers mail for every organization, and MailManager caches each
 * mailer it builds, so mutating `mail.default` / `mail.mailers.smtp` (as the
 * old SettingsService::applyEmailConfig did) let the first organization to
 * send fix the transport and From address for every later job in that worker.
 *
 * The named mailer is purged and rebuilt on every resolution, so a settings
 * change takes effect on the next message and no transport is ever reused
 * across organizations.
 *
 * Returns null (use the system mailer from .env) when the organization has
 * no usable transport configured.
 */
final class OrganizationMailer
{
    public const PREFIX = 'organization_';

    /**
     * The mailer name for this organization, or null for the system mailer.
     */
    public static function for(int $organizationId): ?string
    {
        try {
            $config = self::mailerConfig(SettingsService::getEmailConfig($organizationId));
        } catch (\Throwable $e) {
            Log::warning('Could not load organization mail settings; using the system mailer', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($config === null) {
            return null;
        }

        $name = self::PREFIX.$organizationId;

        Config::set("mail.mailers.{$name}", $config);
        Mail::purge($name);

        return $name;
    }

    /**
     * Translate stored email settings into a Laravel mailer config, or null
     * when the settings do not describe a usable transport.
     *
     * @param  array{provider: string|null, from_address: string|null, from_name: string|null, smtp: array<string, mixed>, mailgun: array<string, mixed>, sendgrid: array<string, mixed>}  $settings
     * @return array<string, mixed>|null
     */
    public static function mailerConfig(array $settings): ?array
    {
        $mailer = match ($settings['provider'] ?? null) {
            'smtp' => self::smtp($settings['smtp']),
            'mailgun' => self::mailgun($settings['mailgun']),
            'sendgrid' => self::sendgrid($settings['sendgrid']),
            // The server's own sendmail binary (PHP mail()).
            'phpmail' => ['transport' => 'sendmail', 'path' => config('mail.mailers.sendmail.path', '/usr/sbin/sendmail -bs -i')],
            default => null,
        };

        if ($mailer === null) {
            return null;
        }

        if (! empty($settings['from_address'])) {
            $mailer['from'] = [
                'address' => $settings['from_address'],
                'name' => $settings['from_name'] ?: $settings['from_address'],
            ];
        }

        return $mailer;
    }

    /**
     * @param  array<string, mixed>  $smtp
     * @return array<string, mixed>|null
     */
    private static function smtp(array $smtp): ?array
    {
        if (empty($smtp['host'])) {
            return null;
        }

        $port = (int) ($smtp['port'] ?: SettingsService::DEFAULT_SMTP_PORT);
        $encryption = $smtp['encryption'] ?? null;

        return [
            'transport' => 'smtp',
            // Implicit TLS on 465 or when "ssl" is chosen; otherwise STARTTLS
            // is negotiated automatically when the server offers it.
            'scheme' => ($encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
            'host' => (string) $smtp['host'],
            'port' => $port,
            'username' => $smtp['username'] ?: null,
            'password' => $smtp['password'] ?: null,
            'timeout' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $mailgun
     * @return array<string, mixed>|null
     */
    private static function mailgun(array $mailgun): ?array
    {
        if (empty($mailgun['domain']) || empty($mailgun['secret'])) {
            return null;
        }

        if (! class_exists(\Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory::class)) {
            Log::warning('Mailgun is selected but symfony/mailgun-mailer is not installed; using the system mailer');

            return null;
        }

        return [
            'transport' => 'mailgun',
            'domain' => (string) $mailgun['domain'],
            'secret' => (string) $mailgun['secret'],
        ];
    }

    /**
     * SendGrid through its SMTP relay (username "apikey", the API key as
     * the password), which needs no extra transport package.
     *
     * @param  array<string, mixed>  $sendgrid
     * @return array<string, mixed>|null
     */
    private static function sendgrid(array $sendgrid): ?array
    {
        if (empty($sendgrid['api_key'])) {
            return null;
        }

        return [
            'transport' => 'smtp',
            'scheme' => 'smtp',
            'host' => 'smtp.sendgrid.net',
            'port' => 587,
            'username' => 'apikey',
            'password' => (string) $sendgrid['api_key'],
            'timeout' => null,
        ];
    }
}
