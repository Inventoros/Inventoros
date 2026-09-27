<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;

/**
 * PostgreSQL connection whose query grammar treats LIKE as case-insensitive,
 * matching how the same searches behave on MySQL and SQLite.
 *
 * Registered for the pgsql driver in AppServiceProvider.
 */
class PostgresConnection extends BasePostgresConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new PostgresGrammar($this);
    }
}
