<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional approval gates on purchase orders and stock transfers, mirroring
 * the approval columns orders already carry. approval_status stays NULL when
 * no approval was asked for, so existing rows (and organizations that never
 * turn approvals on) are unaffected: NULL means "no approval gate applies".
 */
return new class extends Migration
{
    private const TABLES = ['purchase_orders', 'stock_transfers'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // pending, approved, rejected
                $table->string('approval_status', 20)->nullable()->after('status');
                $table->foreignId('approval_requested_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
                $table->timestamp('approval_requested_at')->nullable()->after('approval_requested_by');
                $table->foreignId('approved_by')->nullable()->after('approval_requested_at')->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable()->after('approved_by');
                $table->text('approval_notes')->nullable()->after('approved_at');

                $table->index(['organization_id', 'approval_status']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'approval_status']);
                $table->dropForeign(['approval_requested_by']);
                $table->dropForeign(['approved_by']);
                $table->dropColumn([
                    'approval_status', 'approval_requested_by', 'approval_requested_at',
                    'approved_by', 'approved_at', 'approval_notes',
                ]);
            });
        }
    }
};
