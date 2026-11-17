<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Searches ignore apostrophes and quotes (App\Support\Search, issue #430), so
 * on PostgreSQL they compare translate(column::text, <quotes>, '') with ILIKE.
 * The plain trigram indexes from 2026_05_29_000002 cannot serve that
 * expression, so this adds a trigram index on the same expression for the
 * same columns, keeping searches index-backed.
 *
 * QUOTES is a frozen copy of App\Support\Search::IGNORED; the expression only
 * matches the index while the two are identical (tests/Unit/SearchIndexTest
 * checks this). Changing the quote list needs a new migration.
 *
 * Postgres-only: a no-op on SQLite and MySQL, where a leading-wildcard LIKE
 * never used an index.
 */
return new class extends Migration
{
    public const QUOTES = "'\"\u{2018}\u{2019}\u{201B}\u{02BC}\u{2032}\u{FF07}\u{201C}\u{201D}\u{201E}\u{201F}\u{2033}\u{FF02}";

    /**
     * @var array<string, array<int, string>>
     */
    public array $indexes = [
        'products' => ['name', 'sku', 'barcode'],
        'orders' => ['order_number', 'customer_name'],
        'customers' => ['name', 'email'],
        'suppliers' => ['name', 'email'],
        'purchase_orders' => ['po_number'],
    ];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach ($this->indexes as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    "CREATE INDEX IF NOT EXISTS %s ON %s USING gin ((translate(%s::text, '%s', '')) gin_trgm_ops)",
                    $this->indexName($table, $column),
                    $table,
                    $column,
                    str_replace("'", "''", self::QUOTES),
                ));
            }
        }
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        foreach ($this->indexes as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement('DROP INDEX IF EXISTS '.$this->indexName($table, $column));
            }
        }
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    private function indexName(string $table, string $column): string
    {
        return "{$table}_{$column}_unquoted_trgm_idx";
    }
};
