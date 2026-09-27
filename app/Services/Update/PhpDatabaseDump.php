<?php

declare(strict_types=1);

namespace App\Services\Update;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Pure-PHP SQL dump and restore, used when mysqldump / pg_dump cannot run
 * (exec() disabled, binaries not installed, typical of shared hosting).
 *
 * The dump is plain SQL. Every statement is followed by a separator comment
 * line carrying a random token declared in the header, so restore can split
 * statements without parsing SQL and without being fooled by values that
 * contain semicolons or newlines. The file stays valid SQL for other tools.
 *
 * Every driver dumps structure as well as rows, so a restore after a failed
 * migration puts the old schema back, not just the old data:
 * - MySQL/MariaDB: DROP + CREATE from SHOW CREATE TABLE.
 * - SQLite: DROP + CREATE from sqlite_master; indexes and triggers are
 *   re-created after the data.
 * - PostgreSQL: there is no SHOW CREATE TABLE, so the DDL is rebuilt from
 *   pg_catalog: extensions, sequences, columns (types, collations, defaults,
 *   identity, generated columns, NOT NULL) and primary/unique/check/
 *   exclusion constraints; after the data, the remaining indexes, foreign
 *   keys and sequence positions.
 *
 * A "complete" dump (every table in the schema) also drops, on restore,
 * tables that did not exist when it was taken, such as one created by the
 * migration that failed.
 *
 * Restore runs inside a single transaction. On MySQL, DDL commits
 * implicitly, so only the data load is transactional there.
 */
final class PhpDatabaseDump
{
    public const FORMAT = 1;

    private const CHUNK_SIZE = 500;

    private const ROWS_PER_INSERT = 100;

    public function __construct(private Connection $connection) {}

    /**
     * @param  list<string>  $tables
     * @param  bool  $complete  $tables is every table in the schema; restore then drops tables not in the dump
     */
    public function dump(array $tables, string $outputPath, bool $complete = false): void
    {
        $handle = @fopen($outputPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not open {$outputPath} for writing");
        }

        $driver = $this->driver();
        $separator = '-- inventoros-statement-end '.bin2hex(random_bytes(8));

        $write = function (string $text) use ($handle): void {
            if (fwrite($handle, $text) !== strlen($text)) {
                throw new RuntimeException('Could not write the database dump (disk full?)');
            }
        };
        $statement = fn (string $sql) => $write($sql.";\n".$separator."\n");

        $write("-- Inventoros PHP database dump\n");
        $write('-- format: '.self::FORMAT."\n");
        $write("-- driver: {$driver}\n");
        $write("-- separator: {$separator}\n");
        $write('-- complete: '.($complete ? '1' : '0')."\n");
        $write('-- tables: '.implode(',', array_map('rawurlencode', $tables))."\n");
        $write("\n");

        $ordered = $this->orderByDependencies($tables);

        // A consistent snapshot across all tables while the app stays live.
        // MySQL takes the isolation level before the transaction starts,
        // PostgreSQL as the first statement inside it.
        $outermost = $this->connection->transactionLevel() === 0;
        if ($outermost && $this->isMysql()) {
            $this->connection->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        $this->connection->beginTransaction();

        try {
            if ($outermost && $driver === 'pgsql') {
                $this->connection->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            foreach ($this->prologue($ordered) as $sql) {
                $statement($sql);
            }

            foreach ($this->schemaStatements($ordered) as $sql) {
                $statement($sql);
            }

            foreach ($ordered as $table) {
                $this->dumpRows($table, $statement);
            }

            foreach ($this->epilogue($ordered) as $sql) {
                $statement($sql);
            }
        } finally {
            $this->connection->rollBack();
            fclose($handle);
        }
    }

    /**
     * Replay a dump produced by dump().
     */
    public function restore(string $sqlPath): void
    {
        $handle = @fopen($sqlPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open {$sqlPath}");
        }

        try {
            $header = $this->readHeader($handle);

            if ($header['driver'] !== $this->driver()) {
                throw new RuntimeException(
                    "This dump was taken from a {$header['driver']} database and cannot be restored into {$this->driver()}"
                );
            }

            $mysql = $this->isMysql();

            // MySQL cannot roll back DDL, so its transaction starts at the
            // first data statement. Everywhere else the whole restore is one
            // transaction.
            if (! $mysql) {
                $this->connection->beginTransaction();
            }

            try {
                if ($header['complete']) {
                    $this->dropTablesNotIn($header['tables']);
                }

                $buffer = '';
                while (($line = fgets($handle)) !== false) {
                    if (rtrim($line, "\r\n") === $header['separator']) {
                        $sql = substr($buffer, 0, -2); // strip the ";\n" terminator
                        $buffer = '';

                        if ($mysql && ! $this->connection->transactionLevel() && str_starts_with($sql, 'INSERT')) {
                            $this->connection->beginTransaction();
                        }

                        $this->connection->unprepared($sql);

                        continue;
                    }

                    $buffer .= $line;
                }

                if (trim($buffer) !== '') {
                    throw new RuntimeException('The database dump is truncated (trailing statement without terminator)');
                }

                if ($this->connection->transactionLevel() > 0) {
                    $this->connection->commit();
                }
            } catch (\Throwable $e) {
                if ($this->connection->transactionLevel() > 0) {
                    $this->connection->rollBack();
                }

                throw $e;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array{driver: string, separator: string, complete: bool, tables: list<string>}
     */
    private function readHeader($handle): array
    {
        $first = fgets($handle);
        if ($first === false || ! str_starts_with($first, '-- Inventoros PHP database dump')) {
            throw new RuntimeException('Not an Inventoros PHP database dump');
        }

        $meta = [];
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            if (preg_match('/^-- (\w+): (.+)$/', $line, $m)) {
                $meta[$m[1]] = $m[2];
            }
        }

        if ((int) ($meta['format'] ?? 0) !== self::FORMAT || empty($meta['driver']) || empty($meta['separator'])) {
            throw new RuntimeException('Unsupported or corrupt database dump header');
        }

        $tables = ($meta['tables'] ?? '') === ''
            ? []
            : array_map('rawurldecode', explode(',', $meta['tables']));

        return [
            'driver' => $meta['driver'],
            'separator' => $meta['separator'],
            'complete' => ($meta['complete'] ?? '0') === '1',
            'tables' => $tables,
        ];
    }

    /**
     * Drop tables that exist now but were not in the dump (for example one
     * created by the migration that failed), so a complete restore returns
     * exactly the schema that was backed up.
     *
     * @param  list<string>  $keep
     */
    private function dropTablesNotIn(array $keep): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $current = array_column($schema->getTables($schema->getCurrentSchemaName()), 'name');
        $extra = array_values(array_diff($current, $keep));

        if ($extra === []) {
            return;
        }

        $list = implode(', ', array_map($this->wrap(...), $extra));

        switch ($this->driver()) {
            case 'pgsql':
                $this->connection->unprepared("DROP TABLE IF EXISTS {$list} CASCADE");
                break;
            case 'mysql':
                $this->connection->unprepared('SET FOREIGN_KEY_CHECKS=0');
                $this->connection->unprepared("DROP TABLE IF EXISTS {$list}");
                $this->connection->unprepared('SET FOREIGN_KEY_CHECKS=1');
                break;
            default:
                $this->connection->unprepared('PRAGMA defer_foreign_keys = ON');
                foreach ($extra as $table) {
                    $this->connection->unprepared('DROP TABLE IF EXISTS '.$this->wrap($table));
                }
        }
    }

    private function driver(): string
    {
        $driver = $this->connection->getDriverName();

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    private function isMysql(): bool
    {
        return $this->driver() === 'mysql';
    }

    /**
     * Table names in the dump are the real (prefixed) names; the schema
     * builder's introspection methods add the connection prefix themselves.
     */
    private function unprefixed(string $table): string
    {
        $prefix = $this->connection->getTablePrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }

    private function wrap(string $identifier): string
    {
        return $this->connection->getQueryGrammar()->wrap($identifier);
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function prologue(array $tables): array
    {
        return match ($this->driver()) {
            'mysql' => ['SET FOREIGN_KEY_CHECKS=0'],
            'sqlite' => ['PRAGMA defer_foreign_keys = ON'],
            'pgsql' => $this->pgPrologue($tables),
            default => throw new RuntimeException("The PHP dump does not support the {$this->driver()} driver"),
        };
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function schemaStatements(array $tables): array
    {
        $driver = $this->driver();
        if ($driver === 'pgsql') {
            return $this->pgCreateStatements($tables);
        }

        $statements = [];
        foreach (array_reverse($tables) as $table) {
            $statements[] = 'DROP TABLE IF EXISTS '.$this->wrap($table);
        }

        foreach ($tables as $table) {
            if ($driver === 'mysql') {
                $row = (array) $this->connection->selectOne('SHOW CREATE TABLE '.$this->wrap($table));
                $statements[] = (string) ($row['Create Table'] ?? array_values($row)[1]);
            } else {
                $statements[] = (string) $this->connection->scalar(
                    "select sql from sqlite_master where type = 'table' and name = ?",
                    [$table]
                );
            }
        }

        return $statements;
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function epilogue(array $tables): array
    {
        $statements = [];

        switch ($this->driver()) {
            case 'mysql':
                $statements[] = 'SET FOREIGN_KEY_CHECKS=1';
                break;

            case 'sqlite':
                foreach ($tables as $table) {
                    $rows = $this->connection->select(
                        "select sql from sqlite_master where type in ('index', 'trigger') and tbl_name = ? and sql is not null order by type, name",
                        [$table]
                    );
                    foreach ($rows as $row) {
                        $statements[] = (string) $row->sql;
                    }
                }
                break;

            case 'pgsql':
                $statements = $this->pgPostDataStatements($tables);
                break;
        }

        return $statements;
    }

    /**
     * @param  \Closure(string): void  $statement
     */
    private function dumpRows(string $table, \Closure $statement): void
    {
        $wrapped = $this->wrap($table);
        $order = $this->orderColumns($table);
        $offset = 0;

        // PostgreSQL: generated columns cannot be inserted, and GENERATED
        // ALWAYS identity columns need OVERRIDING SYSTEM VALUE.
        $select = '*';
        $overriding = '';
        if ($this->driver() === 'pgsql') {
            $pgColumns = $this->pgColumns($table);
            $select = implode(', ', array_map(
                fn ($c) => $this->wrap($c->name),
                array_values(array_filter($pgColumns, fn ($c) => $c->generated === ''))
            ));
            if (array_filter($pgColumns, fn ($c) => $c->identity !== '') !== []) {
                $overriding = ' OVERRIDING SYSTEM VALUE';
            }
        }

        do {
            $rows = $this->connection->select(sprintf(
                'select %s from %s order by %s limit %d offset %d',
                $select,
                $wrapped,
                implode(', ', array_map($this->wrap(...), $order)),
                self::CHUNK_SIZE,
                $offset
            ));
            $offset += self::CHUNK_SIZE;

            foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $batch) {
                $columns = array_keys((array) $batch[0]);
                $values = array_map(
                    fn ($row) => '('.implode(', ', array_map($this->literal(...), array_values((array) $row))).')',
                    $batch
                );

                $statement(sprintf(
                    "INSERT INTO %s (%s)%s VALUES\n%s",
                    $wrapped,
                    implode(', ', array_map($this->wrap(...), $columns)),
                    $overriding,
                    implode(",\n", $values)
                ));
            }
        } while (count($rows) === self::CHUNK_SIZE);
    }

    /**
     * Primary key columns, or every column when a table has no primary key,
     * so chunked pagination is deterministic.
     *
     * @return list<string>
     */
    private function orderColumns(string $table): array
    {
        $schema = $this->connection->getSchemaBuilder();

        foreach ($schema->getIndexes($this->unprefixed($table)) as $index) {
            if (! empty($index['primary'])) {
                return array_values($index['columns']);
            }
        }

        return array_values(array_column($schema->getColumns($this->unprefixed($table)), 'name'));
    }

    // ---------------------------------------------------------------------
    // PostgreSQL DDL reconstruction from pg_catalog
    // ---------------------------------------------------------------------

    /** @var array<string, list<object>> */
    private array $pgColumnCache = [];

    private ?string $pgSchemaName = null;

    private function pgSchema(): string
    {
        return $this->pgSchemaName ??= (string) $this->connection->scalar('select current_schema()');
    }

    /**
     * The table as a regclass literal, qualified with the current schema.
     */
    private function pgRegclass(string $table): string
    {
        return $this->wrap($this->pgSchema()).'.'.$this->wrap($table);
    }

    /**
     * @return list<object>
     */
    private function pgColumns(string $table): array
    {
        return $this->pgColumnCache[$table] ??= $this->connection->select(
            'select a.attname as name,
                    format_type(a.atttypid, a.atttypmod) as type,
                    a.attnotnull as notnull,
                    pg_get_expr(d.adbin, d.adrelid) as default_expr,
                    a.attidentity::text as identity,
                    a.attgenerated::text as generated,
                    case when a.attcollation <> 0 and a.attcollation <> t.typcollation
                         then (select quote_ident(n.nspname) || \'.\' || quote_ident(c.collname)
                               from pg_collation c join pg_namespace n on n.oid = c.collnamespace
                               where c.oid = a.attcollation)
                    end as collation
             from pg_attribute a
             join pg_type t on t.oid = a.atttypid
             left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
             where a.attrelid = ?::regclass and a.attnum > 0 and not a.attisdropped
             order by a.attnum',
            [$this->pgRegclass($table)]
        );
    }

    /**
     * Sequences referenced by nextval() defaults (serial / bigserial),
     * keyed by column name.
     *
     * @return array<string, string>
     */
    private function pgSerialSequences(string $table): array
    {
        $sequences = [];
        foreach ($this->pgColumns($table) as $column) {
            if ($column->default_expr !== null
                && preg_match("/^nextval\\('((?:[^']|'')+)'::regclass\\)$/", $column->default_expr, $m)) {
                $sequences[$column->name] = str_replace("''", "'", $m[1]);
            }
        }

        return $sequences;
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function pgPrologue(array $tables): array
    {
        $statements = [];

        foreach ($this->connection->select("select extname from pg_extension where extname <> 'plpgsql' order by extname") as $extension) {
            $statements[] = 'CREATE EXTENSION IF NOT EXISTS '.$this->wrap($extension->extname);
        }

        if ($tables !== []) {
            // CASCADE also drops foreign keys pointing in from other tables
            // and the sequences these tables own; both are recreated below.
            $statements[] = 'DROP TABLE IF EXISTS '.implode(', ', array_map($this->wrap(...), array_reverse($tables))).' CASCADE';
        }

        return $statements;
    }

    /**
     * CREATE SEQUENCE, CREATE TABLE (columns plus primary key, unique,
     * check and exclusion constraints), then sequence ownership.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function pgCreateStatements(array $tables): array
    {
        $statements = [];

        foreach ($tables as $table) {
            foreach ($this->pgSerialSequences($table) as $sequence) {
                $statements[] = 'CREATE SEQUENCE IF NOT EXISTS '.$sequence;
            }

            $lines = [];
            foreach ($this->pgColumns($table) as $column) {
                $line = $this->wrap($column->name).' '.$column->type;

                if ($column->collation !== null) {
                    $line .= ' COLLATE '.$column->collation;
                }

                if ($column->generated === 's') {
                    $line .= ' GENERATED ALWAYS AS ('.$column->default_expr.') STORED';
                } elseif ($column->identity === 'a') {
                    $line .= ' GENERATED ALWAYS AS IDENTITY';
                } elseif ($column->identity === 'd') {
                    $line .= ' GENERATED BY DEFAULT AS IDENTITY';
                } elseif ($column->default_expr !== null) {
                    $line .= ' DEFAULT '.$column->default_expr;
                }

                if ($column->notnull) {
                    $line .= ' NOT NULL';
                }

                $lines[] = $line;
            }

            $constraints = $this->connection->select(
                "select conname, pg_get_constraintdef(oid) as definition
                 from pg_constraint
                 where conrelid = ?::regclass and contype in ('p', 'u', 'c', 'x')
                 order by case contype when 'p' then 0 when 'u' then 1 else 2 end, conname",
                [$this->pgRegclass($table)]
            );
            foreach ($constraints as $constraint) {
                $lines[] = 'CONSTRAINT '.$this->wrap($constraint->conname).' '.$constraint->definition;
            }

            $statements[] = 'CREATE TABLE '.$this->wrap($table)." (\n    ".implode(",\n    ", $lines)."\n)";

            foreach ($this->pgSerialSequences($table) as $column => $sequence) {
                $statements[] = 'ALTER SEQUENCE '.$sequence.' OWNED BY '.$this->wrap($table).'.'.$this->wrap($column);
            }
        }

        return $statements;
    }

    /**
     * After the data: plain indexes, foreign keys (added last so row order
     * never matters) and sequence positions.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function pgPostDataStatements(array $tables): array
    {
        $indexes = [];
        $foreignKeys = [];
        $sequences = [];
        $pdo = $this->connection->getPdo();

        foreach ($tables as $table) {
            $regclass = $this->pgRegclass($table);

            foreach ($this->connection->select(
                "select pg_get_indexdef(i.indexrelid) as definition
                 from pg_index i
                 where i.indrelid = ?::regclass
                   and not exists (
                       select 1 from pg_constraint c
                       where c.conindid = i.indexrelid and c.conrelid = i.indrelid and c.contype in ('p', 'u', 'x')
                   )
                 order by i.indexrelid",
                [$regclass]
            ) as $index) {
                $indexes[] = (string) $index->definition;
            }

            foreach ($this->connection->select(
                "select conname, pg_get_constraintdef(oid) as definition
                 from pg_constraint where conrelid = ?::regclass and contype = 'f' order by conname",
                [$regclass]
            ) as $constraint) {
                $foreignKeys[] = 'ALTER TABLE '.$this->wrap($table).' ADD CONSTRAINT '.$this->wrap($constraint->conname).' '.$constraint->definition;
            }

            foreach ($this->pgColumns($table) as $column) {
                $isSerial = $column->default_expr !== null && str_starts_with($column->default_expr, 'nextval(');
                if ($column->identity === '' && ! $isSerial) {
                    continue;
                }

                $sequences[] = sprintf(
                    'SELECT setval(pg_get_serial_sequence(%s, %s), COALESCE((SELECT MAX(%s) FROM %s), 0) + 1, false)',
                    $pdo->quote($this->wrap($table)),
                    $pdo->quote($column->name),
                    $this->wrap($column->name),
                    $this->wrap($table)
                );
            }
        }

        return [...$indexes, ...$foreignKeys, ...$sequences];
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_resource($value)) {
            return $this->binaryLiteral((string) stream_get_contents($value));
        }

        if (is_bool($value)) {
            return $this->driver() === 'pgsql' ? ($value ? 'true' : 'false') : ($value ? '1' : '0');
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? (string) $value : 'NULL';
        }

        $value = (string) $value;

        if (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            return $this->binaryLiteral($value);
        }

        return $this->connection->getPdo()->quote($value);
    }

    private function binaryLiteral(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return $this->driver() === 'pgsql'
            ? "decode('{$hex}', 'hex')"
            : "X'{$hex}'";
    }

    /**
     * Parents before children, so inserts satisfy foreign keys even where
     * they cannot be switched off. Cycles fall back to name order.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function orderByDependencies(array $tables): array
    {
        sort($tables);
        $schema = $this->connection->getSchemaBuilder();
        $set = array_flip($tables);

        $dependsOn = [];
        foreach ($tables as $table) {
            $dependsOn[$table] = [];
            foreach ($schema->getForeignKeys($this->unprefixed($table)) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];
                if ($parent !== $table && isset($set[$parent])) {
                    $dependsOn[$table][$parent] = true;
                }
            }
        }

        $ordered = [];
        while ($dependsOn !== []) {
            $ready = array_keys(array_filter($dependsOn, fn ($parents) => $parents === []));

            if ($ready === []) {
                // Cycle: take the alphabetically first remaining table.
                $ready = [array_key_first($dependsOn)];
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($dependsOn[$table]);
                foreach ($dependsOn as &$parents) {
                    unset($parents[$table]);
                }
                unset($parents);
            }
        }

        return $ordered;
    }
}
