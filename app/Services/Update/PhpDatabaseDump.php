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
 * Schema handling per driver:
 * - MySQL/MariaDB: DROP + CREATE from SHOW CREATE TABLE (full fidelity).
 * - SQLite: DROP + CREATE from sqlite_master, indexes/triggers re-created
 *   after the data.
 * - PostgreSQL: data only. Tables are truncated and refilled, and identity
 *   sequences are moved past the restored ids. PostgreSQL has no
 *   SHOW CREATE TABLE equivalent, and Laravel runs each PostgreSQL migration
 *   in its own transaction, so a failed migration does not leave a half
 *   applied schema behind. pg_dump remains the preferred method there.
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
     */
    public function dump(array $tables, string $outputPath): void
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
     * @return array{driver: string, separator: string}
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

        return ['driver' => $meta['driver'], 'separator' => $meta['separator']];
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
            'pgsql' => $tables === [] ? [] : [
                'TRUNCATE TABLE '.implode(', ', array_map($this->wrap(...), $tables)).' RESTART IDENTITY CASCADE',
            ],
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
            return [];
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
                $schema = $this->connection->getSchemaBuilder();
                foreach ($tables as $table) {
                    if (! in_array('id', array_column($schema->getColumns($this->unprefixed($table)), 'name'), true)) {
                        continue;
                    }
                    $wrapped = $this->wrap($table);
                    // pg_get_serial_sequence() is NULL for tables without a
                    // sequence, and setval(NULL, ...) is then a harmless NULL.
                    $statements[] = sprintf(
                        'SELECT setval(pg_get_serial_sequence(%s, %s), COALESCE((SELECT MAX("id") FROM %s), 0) + 1, false)',
                        $this->connection->getPdo()->quote($wrapped),
                        $this->connection->getPdo()->quote('id'),
                        $wrapped
                    );
                }
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

        do {
            $rows = $this->connection->select(sprintf(
                'select * from %s order by %s limit %d offset %d',
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
                    "INSERT INTO %s (%s) VALUES\n%s",
                    $wrapped,
                    implode(', ', array_map($this->wrap(...), $columns)),
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
