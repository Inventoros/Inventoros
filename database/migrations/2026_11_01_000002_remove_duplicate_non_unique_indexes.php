<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Each plain B-tree duplicates a unique B-tree with the same columns.
    // Keep the unique constraint and its index; remove only the plain copy.
    private array $duplicates = [
        'organizations' => ['slug'],
        'plugins' => ['slug'],
        'products' => ['organization_id', 'sku'],
        'stock_transfers' => ['organization_id', 'transfer_number'],
        'system_settings' => ['key'],
    ];

    public function up(): void
    {
        foreach ($this->duplicates as $tableName => $columns) {
            $base = $tableName.'_'.implode('_', $columns);

            if (Schema::hasIndex($tableName, $base.'_unique', 'unique')
                && Schema::hasIndex($tableName, $base.'_index')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($base.'_index'));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->duplicates as $tableName => $columns) {
            $name = $tableName.'_'.implode('_', $columns).'_index';

            if (! Schema::hasIndex($tableName, $name)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
            }
        }
    }
};
