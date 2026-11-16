<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exchange rate a purchase order in a foreign currency was placed at:
 * 1 exchange_rate_from (the PO currency) = exchange_rate exchange_rate_to
 * (the organization's currency), where it came from, and the totals in the
 * organization's currency. It may change while the PO is a draft and is
 * locked (exchange_rate_locked_at) when the PO leaves draft; later rate
 * updates never touch it.
 *
 * Every column is nullable and existing rows are left as they are: a PO in
 * the organization's own currency needs no rate, and an older PO in another
 * currency has "no rate recorded" rather than an invented one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('currency');
            $table->char('exchange_rate_from', 3)->nullable()->after('exchange_rate');
            $table->char('exchange_rate_to', 3)->nullable()->after('exchange_rate_from');
            $table->string('exchange_rate_provider', 64)->nullable()->after('exchange_rate_to');
            $table->date('exchange_rate_effective_date')->nullable()->after('exchange_rate_provider');
            $table->timestamp('exchange_rate_retrieved_at')->nullable()->after('exchange_rate_effective_date');
            // provider, fallback, confirmed (a stale provider rate a user
            // accepted) or manual (a rate a user entered).
            $table->string('exchange_rate_source', 16)->nullable()->after('exchange_rate_retrieved_at');
            $table->foreignId('exchange_rate_set_by')->nullable()->after('exchange_rate_source')->constrained('users')->nullOnDelete();
            $table->timestamp('exchange_rate_locked_at')->nullable()->after('exchange_rate_set_by');
            $table->decimal('converted_subtotal', 20, 2)->nullable()->after('exchange_rate_locked_at');
            $table->decimal('converted_tax', 20, 2)->nullable()->after('converted_subtotal');
            $table->decimal('converted_shipping', 20, 2)->nullable()->after('converted_tax');
            $table->decimal('converted_total', 20, 2)->nullable()->after('converted_shipping');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exchange_rate_set_by');
            $table->dropColumn([
                'exchange_rate', 'exchange_rate_from', 'exchange_rate_to', 'exchange_rate_provider',
                'exchange_rate_effective_date', 'exchange_rate_retrieved_at', 'exchange_rate_source',
                'exchange_rate_locked_at', 'converted_subtotal', 'converted_tax', 'converted_shipping', 'converted_total',
            ]);
        });
    }
};
