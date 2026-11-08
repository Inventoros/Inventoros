<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\System\SystemSetting;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Never turn a database outage into permission to reinstall the application. */
final class InstallationState
{
    public static function installed(): bool
    {
        // Recorded outside the database by the installer. It survives outages,
        // lost tables and restores, and also works with a cached configuration.
        if (config('app.installed', false) === true) {
            return true;
        }

        try {
            $connection = DB::connection();
            $database = $connection->getConfig('database');

            // The shipped .env uses an absent SQLite file before the wizard
            // picks a database. A missing production database is an outage,
            // including on legacy installs that have no durable marker yet.
            if ($connection->getDriverName() === 'sqlite'
                && is_string($database) && $database !== ':memory:' && ! is_file($database)
                && ! is_dir($database)
                && (config('app.env') === 'testing'
                    || (config('app.env') === 'local' && $database === database_path('database.sqlite')))) {
                return false;
            }

            $schema = $connection->getSchemaBuilder();
            if ($schema->hasTable('system_settings') && SystemSetting::get('installed', false) === true) {
                return true;
            }

            // A legacy installation whose setting was lost must not reopen
            // account creation or migrate:fresh while its accounts still exist.
            return $schema->hasTable('users') && $connection->table('users')->exists();
        } catch (\Throwable $e) {
            throw new HttpException(503, 'Cannot verify installation state. Restore database connectivity before retrying.', $e);
        }
    }
}
