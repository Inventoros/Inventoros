<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\User;
use App\Services\Update\BackupService;
use App\Services\Update\PhpDatabaseDump;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The pure-PHP fallback must carry the schema, not just rows: restoring it
 * into an EMPTY database has to rebuild every table, column, index,
 * constraint and sequence of the real, fully migrated app.
 *
 * Runs on whichever database the suite targets: SQLite locally and in the
 * default CI job, a real MySQL 8 / PostgreSQL 16 server on the database
 * legs (phpunit.mysql.xml / phpunit.pgsql.xml).
 */
final class BackupPhpDumpFullSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET_DB = 'inventoros_restore_probe';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing/fullschema_'.uniqid());
        File::makeDirectory($this->dir, 0755, true, true);
    }

    protected function tearDown(): void
    {
        DB::purge('restore_target');
        $this->dropTargetDatabase();
        File::deleteDirectory(storage_path('app/testing'));

        parent::tearDown();
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * A connection to a brand-new, empty database of the same kind.
     */
    private function createEmptyTarget(): Connection
    {
        $source = DB::connection()->getConfig();

        if ($this->driver() === 'sqlite') {
            $file = $this->dir.'/target.sqlite';
            touch($file);
            config(['database.connections.restore_target' => [
                'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);

            return DB::connection('restore_target');
        }

        // CREATE DATABASE runs on its own connection: MySQL would commit the
        // test transaction implicitly, and PostgreSQL refuses it inside one.
        $this->dropTargetDatabase();
        $this->admin()->unprepared('CREATE DATABASE '.self::TARGET_DB);

        config(['database.connections.restore_target' => array_merge($source, ['database' => self::TARGET_DB])]);

        return DB::connection('restore_target');
    }

    private function admin(): Connection
    {
        config(['database.connections.restore_admin' => DB::connection()->getConfig()]);

        return DB::connection('restore_admin');
    }

    private function dropTargetDatabase(): void
    {
        if (! in_array($this->driver(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $this->admin()->unprepared($this->driver() === 'pgsql'
            ? 'DROP DATABASE IF EXISTS '.self::TARGET_DB.' WITH (FORCE)'
            : 'DROP DATABASE IF EXISTS '.self::TARGET_DB);
        DB::purge('restore_admin');
    }

    /**
     * Every table's structure, normalised so two databases can be compared.
     *
     * @return array<string, mixed>
     */
    private function schemaFingerprint(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $tables = array_column($schema->getTables($schema->getCurrentSchemaName()), 'name');
        sort($tables);

        $fingerprint = ['tables' => $tables];

        foreach ($tables as $table) {
            $fingerprint[$table] = match ($connection->getDriverName()) {
                'pgsql' => $this->pgFingerprint($connection, $table),
                'mysql', 'mariadb' => $this->mysqlFingerprint($connection, $table),
                default => array_map(fn ($r) => (array) $r, $connection->select(
                    "select type, name, sql from sqlite_master where tbl_name = ? and name not like 'sqlite_%' order by type, name",
                    [$table]
                )),
            };
        }

        return $fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    private function pgFingerprint(Connection $connection, string $table): array
    {
        $regclass = '"public"."'.$table.'"';

        return [
            'columns' => array_map(fn ($r) => (array) $r, $connection->select(
                'select a.attname, format_type(a.atttypid, a.atttypmod) as type, a.attnotnull,
                        pg_get_expr(d.adbin, d.adrelid) as default_expr, a.attidentity::text as identity
                 from pg_attribute a left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
                 where a.attrelid = ?::regclass and a.attnum > 0 and not a.attisdropped order by a.attnum',
                [$regclass]
            )),
            'constraints' => array_map(fn ($r) => $this->normaliseConstraint((array) $r), $connection->select(
                "select conname, contype::text as contype, pg_get_constraintdef(oid) as def
                 from pg_constraint where conrelid = ?::regclass and contype <> 'n' order by conname",
                [$regclass]
            )),
            'indexes' => array_column($connection->select(
                'select indexname, indexdef from pg_indexes where schemaname = ? and tablename = ? order by indexname',
                ['public', $table]
            ), 'indexdef'),
        ];
    }

    /**
     * From information_schema rather than SHOW CREATE TABLE text: MySQL
     * prints "CHARACTER SET utf8mb4" on columns depending on the database
     * default, even when charset and collation are identical.
     *
     * @return array<string, mixed>
     */
    private function mysqlFingerprint(Connection $connection, string $table): array
    {
        $db = $connection->getDatabaseName();
        $rows = fn (string $sql) => array_map(fn ($r) => (array) $r, $connection->select($sql, [$db, $table]));

        return [
            'table' => $rows('select engine, table_collation from information_schema.tables where table_schema = ? and table_name = ?'),
            'columns' => $rows('select column_name, ordinal_position, column_default, is_nullable, column_type,
                    character_set_name, collation_name, column_key, extra
                from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position'),
            'indexes' => $rows('select index_name, non_unique, seq_in_index, column_name, sub_part, index_type
                from information_schema.statistics where table_schema = ? and table_name = ? order by index_name, seq_in_index'),
            'constraints' => $rows('select k.constraint_name, k.column_name, k.ordinal_position, k.referenced_table_name,
                    k.referenced_column_name, r.update_rule, r.delete_rule
                from information_schema.key_column_usage k
                left join information_schema.referential_constraints r
                    on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name
                where k.table_schema = ? and k.table_name = ? order by k.constraint_name, k.ordinal_position'),
        ];
    }

    /**
     * PostgreSQL re-deparses a CHECK expression after it is parsed again,
     * so `(ARRAY['a'::varchar])::text[]` comes back as
     * `ARRAY[('a'::varchar)::text]`: the same constraint, spelled
     * differently (pg_dump round trips show the same thing). Compare CHECK
     * definitions without casts, parentheses and whitespace.
     *
     * @param  array<string, mixed>  $constraint
     * @return array<string, mixed>
     */
    private function normaliseConstraint(array $constraint): array
    {
        if ($constraint['contype'] === 'c') {
            $constraint['def'] = preg_replace(['/::[a-z ]+(\[\])?/', '/[()\s]/'], '', (string) $constraint['def']);
        }

        return $constraint;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowsFingerprint(Connection $connection, array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = $connection->table($table)->count();
        }

        foreach (['organizations', 'users', 'products'] as $table) {
            $rows[$table.'_rows'] = $connection->table($table)->orderBy('id')->get()
                ->map(fn ($r) => array_map(fn ($v) => is_bool($v) ? (int) $v : (is_null($v) ? null : (string) $v), (array) $r))
                ->all();
        }

        return $rows;
    }

    public function test_php_dump_restores_the_full_app_schema_and_rows_into_an_empty_database(): void
    {
        $organization = Organization::create([
            'name' => "Full ' Schema Org", 'email' => 'fs@example.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        User::create([
            'name' => 'Restore Probe', 'email' => 'restore@example.com', 'password' => bcrypt('password'),
            'organization_id' => $organization->id,
        ]);
        foreach (['A', 'B', 'C'] as $i => $suffix) {
            Product::withoutGlobalScopes()->create([
                'organization_id' => $organization->id, 'sku' => "FS-{$suffix}", 'name' => "Product {$suffix}\nline two",
                'price' => 10 + $i, 'currency' => 'USD', 'stock' => $i, 'min_stock' => 0, 'is_active' => $i !== 1,
            ]);
        }

        $source = DB::connection();
        $sourceSchema = $this->schemaFingerprint($source);
        $this->assertNotEmpty($sourceSchema['tables']);
        $this->assertContains('products', $sourceSchema['tables']);
        $sourceRows = $this->rowsFingerprint($source, $sourceSchema['tables']);

        $dumpPath = $this->dir.'/database.sql';
        $service = new class(null, $source->getName()) extends BackupService
        {
            public function dumpTo(string $path): void
            {
                $this->phpDump($path);
            }
        };
        $service->dumpTo($dumpPath);

        $target = $this->createEmptyTarget();
        $this->assertSame([], $this->schemaFingerprint($target)['tables'], 'target database must start empty');

        (new PhpDatabaseDump($target))->restore($dumpPath);

        $restoredSchema = $this->schemaFingerprint($target);
        $this->assertSame($sourceSchema['tables'], $restoredSchema['tables']);
        foreach ($sourceSchema['tables'] as $table) {
            $this->assertEquals($sourceSchema[$table], $restoredSchema[$table], "Schema of {$table} differs after restore");
        }

        $this->assertEquals($sourceRows, $this->rowsFingerprint($target, $sourceSchema['tables']));

        // Sequences / auto-increment continue past the restored ids.
        $maxId = (int) $target->table('products')->max('id');
        $newId = $target->table('products')->insertGetId([
            'organization_id' => $organization->id, 'sku' => 'FS-NEW', 'name' => 'After restore',
            'price' => 1, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->assertGreaterThan($maxId, $newId);
    }
}
