<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $lists = [
        'products' => ['products_org_live_created_index', ['organization_id', 'created_at', 'deleted_at']],
        'orders' => ['orders_org_live_created_index', ['organization_id', 'created_at', 'id', 'deleted_at']],
        'purchase_orders' => ['purchase_orders_org_live_date_index', ['organization_id', 'order_date', 'id', 'deleted_at']],
    ];

    private array $relationships = [
        'return_order_items' => ['return_items_return_index', 'return_order_id'],
        'stock_transfer_items' => ['transfer_items_transfer_index', 'stock_transfer_id'],
        'inter_company_transfer_lines' => ['ict_lines_transfer_index', 'inter_company_transfer_id'],
        'inter_company_transfer_serials' => ['ict_serials_line_index', 'inter_company_transfer_line_id'],
        'inter_company_transfer_batches' => ['ict_batches_line_index', 'inter_company_transfer_line_id'],
    ];

    public function up(): void
    {
        // Tenant equality followed by the actual sort, then the soft-delete
        // marker. Keep deleted_at last: PostgreSQL 16 does not eliminate an
        // IS NULL key from the ordering, so placing it before the date still
        // requires a sort. Products do not filter is_active on the default
        // list; recent dashboard orders sort by created_at, not order_date.
        foreach ($this->lists as $tableName => [$name, $columns]) {
            Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
        }

        // InnoDB already creates these for foreign keys. PostgreSQL and
        // SQLite do not. Reuse any existing leading index on all drivers.
        foreach ($this->relationships as $tableName => [$name, $column]) {
            $covered = collect(Schema::getIndexes($tableName))->contains(
                fn (array $index): bool => ($index['columns'][0] ?? null) === $column
                    && in_array($index['type'], ['btree', null], true)
            );

            if (! $covered) {
                Schema::table($tableName, fn (Blueprint $table) => $table->index($column, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->relationships) as $tableName => [$name]) {
            // Never remove the pre-existing InnoDB foreign-key index.
            if (Schema::hasIndex($tableName, $name)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($name));
            }
        }

        foreach (array_reverse($this->lists) as $tableName => [$name]) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($name));
        }
    }
};
