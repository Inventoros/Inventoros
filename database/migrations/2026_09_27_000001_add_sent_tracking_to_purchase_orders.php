<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the most recent time a purchase order was emailed to its supplier and
 * who it went to, so the PO page can show "Sent to x on date". The full send
 * history (including resends and who sent it) lives in the activity log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('received_date');
            $table->string('sent_to')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'sent_to']);
        });
    }
};
