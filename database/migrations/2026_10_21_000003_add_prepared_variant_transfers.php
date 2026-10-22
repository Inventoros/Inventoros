<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->string('status')->default('pending')->change();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('stock_dispatched_at')->nullable();
        });
        Schema::table('stock_transfer_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('stock_transfers')->where('status', 'in_transit')->whereNotNull('stock_dispatched_at')->exists()) {
            throw new RuntimeException('Receive dispatched transfers before rolling back this migration.');
        }
        foreach (DB::table('stock_transfers')->where('status', 'prepared')->pluck('id') as $id) {
            DB::table('stock_reservations')->where('owner_type', 'core')->where('owner_reference', 'stock-transfer:'.$id)->whereNull('consumed_at')->whereNull('released_at')->update(['released_at' => now()]);
        }
        DB::table('stock_transfers')->where('status', 'prepared')->update(['status' => 'pending']);
        Schema::table('stock_transfer_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('product_variant_id'));
        Schema::table('stock_transfers', fn (Blueprint $table) => $table->dropColumn(['prepared_at', 'stock_dispatched_at']));
    }
};
