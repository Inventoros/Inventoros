<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_audits', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_audits', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_id'));
    }
};
