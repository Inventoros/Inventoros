<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The global search runs case-insensitive `%term%` matches across these
     * columns. On PostgreSQL that pattern cannot use a btree index, so each
     * search is a sequential scan. A GIN index with gin_trgm_ops (from the
     * pg_trgm extension) makes ILIKE '%term%' index-backed.
     *
     * Postgres-only: on SQLite/MySQL this migration is a no-op (those drivers
     * either lack pg_trgm or use a different index strategy), so the test and
     * non-pgsql environments are unaffected.
     *
     * @var array<string, array<int, string>>
     */
    private array $indexes = [
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
                    'CREATE INDEX IF NOT EXISTS %s ON %s USING gin (%s gin_trgm_ops)',
                    $this->indexName($table, $column),
                    $table,
                    $column,
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
        return "{$table}_{$column}_trgm_idx";
    }
};
