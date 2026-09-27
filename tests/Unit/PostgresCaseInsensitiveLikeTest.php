<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LIKE is case-insensitive on SQLite (ASCII) and MySQL (default collation)
 * but case-sensitive on PostgreSQL, so every search box in the app found
 * "Widget" for "widget" everywhere except PostgreSQL. The pgsql connection
 * compiles `like` as `ilike` so searches behave the same on every database.
 *
 * Only SQL compilation is exercised, so no PostgreSQL server is needed.
 */
final class PostgresCaseInsensitiveLikeTest extends TestCase
{
    private function connection(): PostgresConnection
    {
        return new PostgresConnection(fn () => null, 'inventoros', '', ['driver' => 'pgsql', 'name' => 'pgsql']);
    }

    public function test_like_compiles_to_ilike(): void
    {
        $sql = $this->connection()->table('products')
            ->where('name', 'like', '%widget%')
            ->orWhere('sku', 'LIKE', '%widget%')
            ->toSql();

        $this->assertSame(
            'select * from "products" where "name"::text ilike ? or "sku"::text ilike ?',
            $sql
        );
    }

    public function test_not_like_compiles_to_not_ilike(): void
    {
        $sql = $this->connection()->table('products')->where('name', 'not like', '%x%')->toSql();

        $this->assertStringContainsString('"name"::text not ilike ?', $sql);
    }

    public function test_explicit_case_sensitive_where_like_is_left_alone(): void
    {
        $sql = $this->connection()->table('products')
            ->whereLike('sku', 'ABC%', caseSensitive: true)
            ->toSql();

        $this->assertStringContainsString('"sku"::text like ?', $sql);
        $this->assertStringNotContainsString('ilike', $sql);
    }

    public function test_the_app_resolves_pgsql_connections_to_the_case_insensitive_connection(): void
    {
        config(['database.connections.pgsql_probe' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'x', 'password' => '',
        ]]);

        // Resolving does not connect; the PDO is created lazily.
        $this->assertInstanceOf(PostgresConnection::class, DB::connection('pgsql_probe'));
    }
}
