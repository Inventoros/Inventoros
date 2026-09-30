<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Before 2.0.0, a product created through REST, GraphQL, MCP or the products
 * import without a currency was stored as USD (the column default), whatever
 * its organization's currency. From 2.0.0 an order line without a unit price
 * uses the product's own price only when the order is in the product's
 * currency, so those products would make API orders in the organization's
 * currency fail. Move every USD product of an organization whose currency is
 * not USD to that currency.
 *
 * An organization that really prices some products in USD should set their
 * USD price in the product's per-currency prices after upgrading (see
 * UPGRADE.md). Not reversible: afterwards the moved products cannot be told
 * apart from ones entered in the organization's currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        $organizations = DB::table('organizations')
            ->whereNotNull('currency')
            ->pluck('currency', 'id');

        foreach ($organizations as $organizationId => $currency) {
            $currency = strtoupper(trim((string) $currency));

            if ($currency === '' || $currency === 'USD') {
                continue;
            }

            DB::table('products')
                ->where('organization_id', $organizationId)
                ->whereRaw('UPPER(currency) = ?', ['USD'])
                ->update(['currency' => $currency]);
        }
    }

    public function down(): void
    {
        // Irreversible: see the class docblock.
    }
};
