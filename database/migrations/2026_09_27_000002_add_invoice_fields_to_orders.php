<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give order invoices a real identity: a per-organization invoice number
 * assigned the first time the invoice is generated, plus when it was issued
 * and when (and to whom) it was last emailed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('order_number');
            $table->timestamp('invoice_issued_at')->nullable()->after('invoice_number');
            $table->timestamp('invoice_sent_at')->nullable()->after('invoice_issued_at');
            $table->string('invoice_sent_to')->nullable()->after('invoice_sent_at');

            $table->unique(['organization_id', 'invoice_number']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'invoice_number']);
            $table->dropColumn(['invoice_number', 'invoice_issued_at', 'invoice_sent_at', 'invoice_sent_to']);
        });
    }
};
