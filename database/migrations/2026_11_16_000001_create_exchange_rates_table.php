<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange rates from providers whose data is public and the same for every
 * customer (Frankfurter's ECB reference rates). One row per provider, pair
 * and effective date, shared by every organization, so many organizations
 * needing EUR->HUF cause one request. History is kept: a newer rate is a new
 * row, never an overwrite of an older date.
 *
 * Rates a provider licenses per customer, and rates an administrator enters,
 * are tenant data and live in organization_exchange_rates instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            // 1 base_currency = rate quote_currency.
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            // The date the provider says the rate is for (ECB publishes on
            // working days only; a weekend request returns Friday's rate).
            $table->date('effective_date');
            $table->timestamp('retrieved_at');
            $table->timestamps();

            $table->unique(['provider', 'base_currency', 'quote_currency', 'effective_date'], 'exchange_rates_pair_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
