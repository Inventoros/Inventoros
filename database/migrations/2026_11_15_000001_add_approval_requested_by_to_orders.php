<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent an approved sales order back for approval by changing its terms.
 * Like the order's creator, that user may not decide the order themselves.
 * NULL for orders that were never re-opened, so existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('approval_requested_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['approval_requested_by']);
            $table->dropColumn('approval_requested_by');
        });
    }
};
