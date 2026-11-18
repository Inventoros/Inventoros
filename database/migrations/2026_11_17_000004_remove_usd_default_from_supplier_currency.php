<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier without a currency bills in its organization's currency. The
 * Supplier model fills that in on every save, but the column still defaulted
 * to USD, so a row written without going through the model was silently
 * priced in dollars. Drop the default (the column stays NOT NULL, so such a
 * write now fails instead of guessing) and give any supplier saved with a
 * blank currency its organization's currency.
 *
 * Suppliers stored as USD are left alone: an explicitly chosen USD cannot be
 * told apart from the old default.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fallback = preg_replace('/[^A-Z]/', '', strtoupper((string) config('currencies.default', 'USD'))) ?: 'USD';

        DB::table('suppliers')
            ->whereRaw("TRIM(COALESCE(currency, '')) = ''")
            ->update([
                'currency' => DB::raw("COALESCE((SELECT UPPER(NULLIF(TRIM(organizations.currency), '')) FROM organizations WHERE organizations.id = suppliers.organization_id), '{$fallback}')"),
            ]);

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('currency', 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->change();
        });
    }
};
