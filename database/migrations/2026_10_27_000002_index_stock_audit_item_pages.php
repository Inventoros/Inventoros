<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_audit_items', fn (Blueprint $table) => $table->index(['stock_audit_id', 'id'], 'audit_items_audit_page_index'));
    }

    public function down(): void
    {
        Schema::table('stock_audit_items', fn (Blueprint $table) => $table->dropIndex('audit_items_audit_page_index'));
    }
};
