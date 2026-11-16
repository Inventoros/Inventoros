<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange rates that belong to one organization: rates its administrators
 * enter by hand (provider "manual") and rates from providers that declare
 * their data is not shareable between customers (for example a paid feed
 * licensed per customer). Same shape as exchange_rates plus the owner and,
 * for manual rates, who entered it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            $table->date('effective_date');
            $table->timestamp('retrieved_at');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'provider', 'base_currency', 'quote_currency', 'effective_date'], 'org_exchange_rates_pair_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_exchange_rates');
    }
};
