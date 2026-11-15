<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Auth\Organization;
use App\Services\OrganizationMailer;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Lists organizations whose saved SMTP host is refused, so their mail is not
 * sent. Run it after upgrading: hosts saved before the check existed are
 * only refused when mail is sent. Exits 1 when any organization is listed.
 */
class CheckSmtpHostsCommand extends Command
{
    protected $signature = 'inventoros:check-smtp-hosts';

    protected $description = 'List organizations whose saved SMTP host is refused (mail for them is not sent)';

    public function handle(): int
    {
        $rows = [];

        Organization::query()->orderBy('id')->each(function (Organization $organization) use (&$rows): void {
            $settings = SettingsService::getEmailConfig((int) $organization->id);
            $host = trim((string) ($settings['smtp']['host'] ?? ''));

            if (($settings['provider'] ?? null) !== 'smtp' || $host === '') {
                return;
            }

            $refusal = OrganizationMailer::smtpHostRefusal((int) $organization->id, $host);
            if ($refusal !== null) {
                $rows[] = [
                    $organization->id,
                    $organization->name,
                    $host,
                    $refusal === OrganizationMailer::REFUSED_UNRESOLVED ? 'does not resolve' : 'not a public address',
                ];
            }
        });

        if ($rows === []) {
            $this->info('No organization has a refused SMTP host.');

            return self::SUCCESS;
        }

        $this->table(['Organization', 'Name', 'SMTP host', 'Reason'], $rows);
        $this->warn('Mail for these organizations is not sent. Change their SMTP host, or list a trusted relay in INVENTOROS_SMTP_ALLOWED_HOSTS.');

        return self::FAILURE;
    }
}
